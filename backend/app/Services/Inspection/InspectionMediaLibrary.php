<?php

namespace App\Services\Inspection;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mime\MimeTypes;
use Throwable;
use ZipArchive;

/**
 * Photos and documents attached to an inspection record.
 *
 * Everything lives on the private `inspection_media` disk through the already
 * installed media library, so there is no second attachment entity and no path or
 * URL ever reaches a client — the bytes are only served by the authenticated,
 * record-scoped endpoints that call the response helpers at the bottom.
 */
class InspectionMediaLibrary
{
    /**
     * The two collections and the exact envelope each one accepts.
     *
     * `types` maps an extension to the content types a file carrying that extension is
     * allowed to actually hold. It is deliberately a map rather than two independent
     * lists: checking a union of extensions against a union of content types lets any
     * accepted content wear any accepted name, which is how a generic ZIP passes as a
     * DOCX. Here `.docx` may only hold document bytes and `.xls` may only hold an old
     * Excel record stream.
     */
    private const COLLECTIONS = [
        'photos' => [
            'max_items' => 10,
            'max_kilobytes' => 10240,
            'types' => [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
            ],
        ],
        'files' => [
            'max_items' => 10,
            'max_kilobytes' => 20480,
            'types' => [
                'pdf' => ['application/pdf'],
                // The old Office formats are OLE compound files, and a detector reports
                // either the specific type or the bare container depending on how much
                // of the document it recognises — the same shape as the OOXML pair
                // below, and handled the same way.
                'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
                'doc' => ['application/msword', 'application/x-ole-storage'],
                // A CSV is plain text, and detectors disagree on which of these three
                // names to give it, so all three are accepted for that one extension.
                'csv' => ['text/csv', 'text/plain', 'application/csv'],
                'zip' => ['application/zip', 'application/x-zip-compressed'],
                // An OOXML file is a ZIP, and the detector reports either the specific
                // type or the bare container depending on how the archive was written.
                // Accepting the container is what makes the structural check below load
                // bearing rather than decorative.
                'xlsx' => [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/zip',
                    'application/x-zip-compressed',
                ],
                'docx' => [
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/zip',
                    'application/x-zip-compressed',
                ],
            ],
        ],
    ];

    /**
     * The stream that only a document of that old Office format carries, so a bare OLE
     * container — and a workbook wearing a `.doc` name — cannot pass as a Word file.
     *
     * The names are read as raw bytes rather than by parsing the compound file: a
     * directory entry stores its name as UTF-16LE, so the encoded name being present
     * is what distinguishes the formats. This is a container-content check, not a full
     * parse, and it sits behind the content-type check rather than replacing it.
     */
    private const COMPOUND_STRUCTURE = [
        'doc' => ['WordDocument'],
        'xls' => ['Workbook', 'Book'],
    ];

    private const COMPOUND_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * The part and the declared content type that only a genuine OOXML document
     * carries. A ZIP of holiday photos renamed `.docx` has neither.
     */
    private const OOXML_STRUCTURE = [
        'xlsx' => [
            'part' => 'xl/workbook.xml',
            'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        ],
        'docx' => [
            'part' => 'word/document.xml',
            'content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
        ],
    ];

    private const DISK = 'inspection_media';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'retained_media_ids' => ['sometimes', 'array'],
            'retained_media_ids.*' => ['integer', 'distinct'],
        ];

        foreach (self::COLLECTIONS as $collection => $spec) {
            $rules[$collection] = ['sometimes', 'array', 'max:'.$spec['max_items']];
            $rules[$collection.'.*'] = [
                'file',
                'max:'.$spec['max_kilobytes'],
                $this->envelopeRule($collection),
            ];
        }

        return $rules;
    }

    /**
     * Every content type a collection may end up storing, which is what the media
     * collection itself is registered to accept. Derived from the same map the request
     * rules use so the two can never drift apart.
     *
     * @return array<int, string>
     */
    public function acceptedMimeTypes(string $collection): array
    {
        $mimeTypes = [];

        foreach (self::COLLECTIONS[$collection]['types'] as $allowed) {
            foreach ($allowed as $mimeType) {
                $mimeTypes[$mimeType] = true;
            }
        }

        return array_keys($mimeTypes);
    }

    /**
     * @return array<int, string>
     */
    public function acceptedExtensions(string $collection): array
    {
        return array_keys(self::COLLECTIONS[$collection]['types']);
    }

    /**
     * The existing media an edit keeps.
     *
     * An absent `retained_media_ids` keeps everything, so a payload that only corrects
     * a measurement can never drop an attachment. When the field is present it is
     * authoritative, and it may only name media this record owns — an id belonging to
     * another record can never be grafted on, nor used to probe what exists.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, Media>
     */
    public function retainedMedia(HasMedia&Model $record, array $payload, string $messagePrefix): array
    {
        $existing = $this->allMedia($record);

        if (! array_key_exists('retained_media_ids', $payload)) {
            return $existing->values()->all();
        }

        $requested = array_values(array_unique(array_map('intval', $payload['retained_media_ids'])));

        if (array_diff($requested, $existing->keys()->all()) !== []) {
            throw ValidationException::withMessages([
                'retained_media_ids' => [$messagePrefix.'_retained_media_invalid'],
            ]);
        }

        return $existing->only($requested)->values()->all();
    }

    /**
     * Groups the uploaded files by collection and refuses a request that would push a
     * collection past its item limit once the retained media are counted in.
     *
     * @param  array<int, Media>  $retainedMedia
     * @return array<string, array<int, UploadedFile>>
     */
    public function validatedUploads(Request $request, array $retainedMedia, string $messagePrefix): array
    {
        $uploads = [];

        foreach (self::COLLECTIONS as $collection => $spec) {
            $files = array_values(array_filter(
                is_array($request->file($collection)) ? $request->file($collection) : [],
                fn (mixed $file): bool => $file instanceof UploadedFile,
            ));
            $retainedCount = count(array_filter(
                $retainedMedia,
                fn (Media $media): bool => $media->collection_name === $collection,
            ));

            if ($retainedCount + count($files) > $spec['max_items']) {
                throw ValidationException::withMessages([
                    $collection => [$messagePrefix.'_'.$collection.'_limit_exceeded'],
                ]);
            }

            $uploads[$collection] = $files;
        }

        return $uploads;
    }

    /**
     * Writes the uploads, collecting each created media row into `$written` as it goes.
     *
     * `$written` is filled by reference rather than returned so it survives a throw:
     * on failure the caller needs the rows this request created, and a return value
     * never arrives. The library inserts the row before it copies the bytes, so an
     * item interrupted mid-write leaves a row that this method can still see while the
     * transaction is open — and only while it is open. Catching here is therefore the
     * last moment the interrupted item can be identified at all.
     *
     * Nothing outside this record is ever looked at, so a concurrent upload belonging
     * to some other record is invisible to the cleanup and cannot be caught by it.
     *
     * @param  array<string, array<int, UploadedFile>>  $uploads
     * @param  array<int, int>  $existingMediaIds  media the record already had
     * @param  array<int, Media>  $written
     */
    public function attach(HasMedia&Model $record, array $uploads, array $existingMediaIds, array &$written): void
    {
        try {
            foreach ($uploads as $collection => $files) {
                foreach ($files as $file) {
                    $originalName = $file->getClientOriginalName();

                    $written[] = $record
                        ->addMedia($file)
                        ->usingName(pathinfo($originalName, PATHINFO_FILENAME))
                        ->withCustomProperties([
                            'original_file_name' => $originalName,
                            'mime_type' => $this->detectedMimeType($file),
                            'size' => $file->getSize(),
                            // Recorded before the file moves onto the disk so the digest is
                            // of exactly what the operator uploaded.
                            'sha256' => hash_file('sha256', $file->getPathname()),
                        ])
                        ->toMediaCollection($collection);
                }
            }
        } catch (Throwable $exception) {
            $written = $this->mediaAddedDuringWrite($record, $existingMediaIds, $written);

            throw $exception;
        }
    }

    /** The ids of the media a record carries right now, taken before a write. */
    public function existingMediaIds(HasMedia&Model $record): array
    {
        return $this->allMedia($record)->keys()->all();
    }

    /**
     * Every media row on this record that neither existed before the write nor came
     * back from a completed `addMedia`. That is exactly the item a failure interrupted
     * between its insert and its bytes.
     *
     * @param  array<int, int>  $existingMediaIds
     * @param  array<int, Media>  $written
     * @return array<int, Media>
     */
    private function mediaAddedDuringWrite(HasMedia&Model $record, array $existingMediaIds, array $written): array
    {
        $known = [...$existingMediaIds, ...array_map(fn (Media $media): int => (int) $media->getKey(), $written)];

        return [
            ...$written,
            ...$this->allMedia($record)
                ->reject(fn (Media $media): bool => in_array((int) $media->getKey(), $known, true))
                ->values()
                ->all(),
        ];
    }

    /**
     * Drops the media an edit did not retain. Runs after the write has committed, so
     * a failed save leaves the previous attachment set untouched.
     *
     * @param  array<int, Media>  $retainedMedia
     * @param  array<int, Media>  $written
     */
    public function deleteRemoved(HasMedia&Model $record, array $retainedMedia, array $written): void
    {
        $keep = array_map(fn (Media $media): int => (int) $media->getKey(), [...$retainedMedia, ...$written]);

        $this->allMedia($record)
            ->reject(fn (Media $media): bool => in_array((int) $media->getKey(), $keep, true))
            ->each(fn (Media $media) => $media->delete());
    }

    /**
     * Removes the bytes of media whose database rows a rolled-back transaction has
     * already taken away.
     *
     * The library stores each item in a directory named after its id, so this deletes
     * one named directory per item it was handed and nothing else. It never scans the
     * disk and never reasons about ids it was not given, which is what keeps another
     * request's in-flight upload — a row not yet committed, and so indistinguishable
     * from an orphan by any global query — safely out of reach.
     *
     * @param  array<int, Media>  $written
     */
    public function discardFiles(array $written): void
    {
        $disk = Storage::disk(self::DISK);

        foreach ($written as $media) {
            $disk->deleteDirectory((string) $media->getKey());
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serializeCollection(HasMedia&Model $record, string $collection): array
    {
        return $record->getMedia($collection)
            ->map(fn (Media $media): array => $this->serialize($media))
            ->values()
            ->all();
    }

    /**
     * Metadata only. No disk path and no URL: the bytes are reachable exclusively
     * through the record-scoped endpoints.
     *
     * @return array<string, mixed>
     */
    public function serialize(Media $media): array
    {
        return [
            'id' => (int) $media->getKey(),
            'collection' => $media->collection_name,
            'file_name' => $media->getCustomProperty('original_file_name') ?? $media->file_name,
            'mime_type' => $media->getCustomProperty('mime_type') ?? $media->mime_type,
            'size' => (int) ($media->getCustomProperty('size') ?? $media->size),
            'sha256' => $media->getCustomProperty('sha256'),
            'created_at' => $media->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Resolves a media id against the record that owns it. A media row belonging to a
     * different record — or to the wrong collection — is reported as missing rather
     * than forbidden, so the endpoint cannot be used to enumerate other records.
     */
    public function ownedMedia(HasMedia&Model $record, Media $media, ?string $collection = null): Media
    {
        $ownedByRecord = $media->model_type === $record->getMorphClass()
            && (int) $media->model_id === (int) $record->getKey();

        abort_unless($ownedByRecord && ($collection === null || $media->collection_name === $collection), 404);

        return $media;
    }

    public function inlineResponse(Media $media): BinaryFileResponse
    {
        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadResponse(Media $media): BinaryFileResponse
    {
        return response()->download(
            $media->getPath(),
            $media->getCustomProperty('original_file_name') ?? $media->file_name,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * @return Collection<int, Media>
     */
    private function allMedia(HasMedia&Model $record): Collection
    {
        return Media::query()
            ->where('model_type', $record->getMorphClass())
            ->where('model_id', $record->getKey())
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Media $media): int => (int) $media->getKey());
    }

    /**
     * The name has to be one this collection accepts, and the bytes have to be what
     * that particular name promises.
     *
     * The content type is sniffed from the bytes rather than read from the upload
     * header, which a client controls, and it is sniffed exactly the way the media
     * library sniffs it — so a file this rule accepts is a file the collection will
     * accept, and its own guard can never turn a rejected upload into a 500.
     */
    private function envelopeRule(string $collection): Closure
    {
        $types = self::COLLECTIONS[$collection]['types'];

        return function (string $attribute, mixed $value, Closure $fail) use ($types): void {
            if (! $value instanceof UploadedFile) {
                $fail('inspection_media_invalid_upload');

                return;
            }

            $extension = strtolower($value->getClientOriginalExtension());
            $allowedMimeTypes = $types[$extension] ?? null;

            if ($allowedMimeTypes === null) {
                $fail('inspection_media_extension_not_allowed');

                return;
            }

            if (! in_array($this->detectedMimeType($value), $allowedMimeTypes, true)) {
                $fail('inspection_media_content_does_not_match_extension');

                return;
            }

            // Every extension whose accepted content types include a generic container
            // needs the container opened, because the content type alone cannot tell one
            // kind of document from another kind of the same container.
            if (! $this->hasExpectedStructure($value->getPathname(), $extension)) {
                $fail('inspection_media_content_does_not_match_extension');
            }
        };
    }

    /**
     * Confirms a container really holds the kind of document its extension claims.
     * Extensions that are not containers have nothing to open and pass straight through.
     */
    private function hasExpectedStructure(string $path, string $extension): bool
    {
        if (isset(self::OOXML_STRUCTURE[$extension])) {
            return $this->isOoxmlDocument($path, $extension);
        }

        if (isset(self::COMPOUND_STRUCTURE[$extension])) {
            return $this->isCompoundDocument($path, $extension);
        }

        return true;
    }

    /**
     * Confirms an OLE compound file carries a stream that belongs to the claimed
     * format. Only the header and the directory names are read, never the document.
     */
    private function isCompoundDocument(string $path, string $extension): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $signature = (string) fread($handle, strlen(self::COMPOUND_SIGNATURE));

        if ($signature !== self::COMPOUND_SIGNATURE) {
            fclose($handle);

            return false;
        }

        // The directory sits within the first sectors of any real document, so a bounded
        // read is enough and an oversized upload is never loaded whole to check its name.
        $head = (string) fread($handle, 512 * 1024);
        fclose($handle);

        foreach (self::COMPOUND_STRUCTURE[$extension] as $stream) {
            if (str_contains($head, (string) mb_convert_encoding($stream, 'UTF-16LE', 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Confirms an archive really is the OOXML document its extension claims.
     *
     * Both halves matter: the part proves the document body exists, and the declared
     * content type proves the package describes itself as that kind of document. A ZIP
     * that merely contains a file at the right path still fails the second check.
     */
    private function isOoxmlDocument(string $path, string $extension): bool
    {
        $structure = self::OOXML_STRUCTURE[$extension];
        $archive = new ZipArchive;

        if ($archive->open($path) !== true) {
            return false;
        }

        $contentTypes = $archive->getFromName('[Content_Types].xml');
        $hasBodyPart = $archive->locateName($structure['part']) !== false;
        $archive->close();

        return $hasBodyPart && is_string($contentTypes) && str_contains($contentTypes, $structure['content_type']);
    }

    /** The same content sniffing the media library performs when it stores the file. */
    private function detectedMimeType(UploadedFile $file): string
    {
        return (string) MimeTypes::getDefault()->guessMimeType($file->getPathname());
    }
}
