<?php

namespace Tests\Feature\Equipment;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\EquipmentSystem;
use App\Models\PhotometricCurveInspectionRecord;
use App\Models\Sample;
use App\Models\TestOrder;
use App\Models\User;
use App\Services\Inspection\InspectionMediaLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;

class PhotometricCurveInspectionRecordTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/photometric-curve-inspection-records';

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('inspection_media');
    }

    protected function tearDown(): void
    {
        // Uploads that were rejected are never moved onto the disk, so the fixtures
        // they were built from have to be swept up here.
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    public function test_endpoints_are_denied_without_permissions_and_record_the_denial(): void
    {
        $stranger = $this->userWithPermissions([]);
        $record = $this->record($this->sample('S-DENY'), [$this->equipment('EQ-DENY')]);
        $media = $this->attachPhoto($record);

        $this->getJsonAs($stranger, self::BASE)->assertForbidden();
        $this->getJsonAs($stranger, self::BASE."/{$record->id}")->assertForbidden();
        $this->getJsonAs($stranger, self::BASE.'/equipment')->assertForbidden();
        $this->getJsonAs($stranger, self::BASE.'/lookup?type=equipment&code=EQ-DENY')->assertForbidden();
        $this->getJsonAs($stranger, self::BASE."/{$record->id}/media/{$media->id}/view")->assertForbidden();
        $this->getJsonAs($stranger, self::BASE."/{$record->id}/media/{$media->id}/download")->assertForbidden();
        $this->postJsonAs($stranger, self::BASE, [])->assertForbidden();
        $this->putJsonAs($stranger, self::BASE."/{$record->id}", [])->assertForbidden();
        $this->deleteJsonAs($stranger, self::BASE."/{$record->id}")->assertForbidden();

        $this->assertSame(
            9,
            AuditLog::query()
                ->where('action', 'authorization.denied')
                ->where('module', 'photometric_curve_inspection_records')
                ->count(),
        );
    }

    public function test_lookup_resolves_the_three_codes_without_ledger_read_permissions(): void
    {
        $operator = $this->userWithPermissions(['photometric_curve_inspection_records.create']);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('26010058874-1-1/1');
        $system = $this->system('sys-01');

        $this->getJsonAs($operator, '/api/equipment')->assertForbidden();
        $this->getJsonAs($operator, '/api/samples')->assertForbidden();
        $this->getJsonAs($operator, self::BASE)->assertForbidden();

        $this->getJsonAs($operator, self::BASE.'/lookup?type=equipment&code=XPD-S-001')
            ->assertOk()
            ->assertJsonPath('data.id', $equipment->id)
            ->assertJsonPath('data.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.equipment_name', '智能交流测试专用电源')
            ->assertJsonPath('data.manufacturer', '杭州远方')
            ->assertJsonPath('data.model', 'DPS1060-V200')
            ->assertJsonPath('data.next_calibration_date', '2027-03-01');

        $this->getJsonAs($operator, self::BASE.'/lookup?type=sample&code=26010058874-1-1/1')
            ->assertOk()
            ->assertJsonPath('data.id', $sample->id)
            ->assertJsonPath('data.sample_no', '26010058874-1-1/1');

        $this->getJsonAs($operator, self::BASE.'/lookup?type=system&code=sys-01')
            ->assertOk()
            ->assertJsonPath('data.id', $system->id)
            ->assertJsonPath('data.code', 'sys-01')
            ->assertJsonPath('data.name', '系统1');

        $this->getJsonAs($operator, self::BASE.'/lookup?type=equipment&code=NOPE')->assertNotFound();
        $this->getJsonAs($operator, self::BASE.'/lookup?type=sample&code=NOPE')->assertNotFound();
    }

    public function test_lookup_is_available_to_editors_and_refuses_a_disabled_system(): void
    {
        $editor = $this->userWithPermissions(['photometric_curve_inspection_records.update']);
        $this->equipment('XPD-S-EDIT');
        $this->system('sys-off', status: 'disabled');

        $this->getJsonAs($editor, self::BASE.'/lookup?type=equipment&code=XPD-S-EDIT')
            ->assertOk()
            ->assertJsonPath('data.equipment_no', 'XPD-S-EDIT');

        $this->getJsonAs($editor, self::BASE.'/lookup?type=system&code=sys-off')->assertNotFound();
    }

    public function test_creating_a_record_snapshots_the_subjects_and_serializes_exact_decimals(): void
    {
        $operator = $this->editor();
        $first = $this->equipment('XPD-S-001');
        $second = $this->equipment('XPD-S-004', name: '数字功率计', model: 'PF310', serialNo: 'G122097CA8361137');
        $sample = $this->sample('26010058874-1-1/1');
        $system = $this->system('sys-01');

        $response = $this->postJsonAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$first->id, $second->id],
            ...$this->measurements(),
        ])->assertCreated();

        $response
            ->assertJsonPath('data.sample_no', '26010058874-1-1/1')
            ->assertJsonPath('data.system_code', 'sys-01')
            ->assertJsonPath('data.system_name', '系统1')
            ->assertJsonPath('data.c0_180', '60.2')
            ->assertJsonPath('data.c30_210', '60.3')
            ->assertJsonPath('data.c60_240', '64.5')
            ->assertJsonPath('data.c90_270', '60.8')
            ->assertJsonPath('data.probe', 'far_field')
            ->assertJsonPath('data.test_distance', '26.0000')
            ->assertJsonPath('data.peak_luminous_intensity', '221.0')
            ->assertJsonPath('data.luminous_flux', '1674.0')
            ->assertJsonPath('data.voltage', '220.8')
            ->assertJsonPath('data.current', '0.1189')
            ->assertJsonPath('data.power', '14.2400')
            ->assertJsonPath('data.power_factor', '0.5422')
            ->assertJsonPath('data.frequency', 50)
            ->assertJsonPath('data.operator_id', $operator->id)
            ->assertJsonPath('data.operator_name', $operator->name)
            ->assertJsonCount(2, 'data.equipment')
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.equipment.0.equipment_name', '智能交流测试专用电源')
            ->assertJsonPath('data.equipment.1.equipment_no', 'XPD-S-004')
            ->assertJsonPath('data.equipment.1.model', 'PF310')
            ->assertJsonPath('data.equipment.1.serial_no', 'G122097CA8361137')
            ->assertJsonPath('data.equipment.1.next_calibration_date', '2027-03-01')
            ->assertJsonPath('data.photos', [])
            ->assertJsonPath('data.files', []);

        $this->assertDatabaseCount('photometric_curve_inspection_equipment', 2);
        $this->assertSame(1, AuditLog::query()->where('action', 'photometric_curve_inspection_records.create')->count());
    }

    public function test_average_angle_is_derived_rounded_half_up_and_never_stored_or_accepted(): void
    {
        $operator = $this->editor();
        $record = $this->createRecord($operator, ['c0_180' => '60.2', 'c30_210' => '60.3', 'c60_240' => '64.5', 'c90_270' => '60.8']);

        // 60.2 + 60.3 + 64.5 + 60.8 = 245.8, a quarter of which is 61.45 exactly, so
        // the tie rounds up. The workbook's hand-typed 61.1 is the failure this removes.
        $this->assertSame('61.5', $record['average_angle']);
        $this->assertArrayNotHasKey('average_angle', PhotometricCurveInspectionRecord::query()->findOrFail($record['id'])->getAttributes());

        $equal = $this->createRecord($operator, ['c0_180' => '60.2', 'c30_210' => '60.2', 'c60_240' => '60.2', 'c90_270' => '60.2']);
        $this->assertSame('60.2', $equal['average_angle']);

        $roundDown = $this->createRecord($operator, ['c0_180' => '0.1', 'c30_210' => '0.1', 'c60_240' => '0.1', 'c90_270' => '0.2']);
        $this->assertSame('0.1', $roundDown['average_angle']);

        $zero = $this->createRecord($operator, ['c0_180' => '0.0', 'c30_210' => '0.0', 'c60_240' => '0.0', 'c90_270' => '0.0']);
        $this->assertSame('0.0', $zero['average_angle']);

        // A client-supplied average is ignored rather than stored: the value keeps
        // following the angles it summarises.
        $supplied = $this->createRecord($operator, ['average_angle' => '999.9']);
        $this->assertSame('61.5', $supplied['average_angle']);
        $this->assertArrayNotHasKey('average_angle', PhotometricCurveInspectionRecord::query()->findOrFail($supplied['id'])->getAttributes());

        $updated = $this->putJsonAs($operator, self::BASE."/{$supplied['id']}", [
            ...$this->measurements(),
            'c60_240' => '70.5',
        ])->assertOk();

        $updated->assertJsonPath('data.average_angle', '63.0');
    }

    public function test_the_recorded_time_is_server_owned_and_a_supplied_one_is_ignored(): void
    {
        $operator = $this->editor();
        $other = $this->userWithPermissions([]);
        $sample = $this->sample('26010058874-2-1/1');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('XPD-S-002');

        $this->travelTo('2026-08-21 10:29:00');

        $created = $this->postJsonAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            // Every one of these is server owned; a client that sends them anyway must
            // not be able to forge the audit trail.
            'recorded_at' => '1999-01-01 00:00:00',
            'operator_id' => $other->id,
            'operator_name' => 'Someone Else',
            ...$this->measurements(),
        ])->assertCreated()->json('data');

        $this->assertSame('2026-08-21 10:29:00', $created['recorded_at']);
        $this->assertSame($operator->id, $created['operator_id']);
        $this->assertSame($operator->name, $created['operator_name']);
        $this->assertSame(0, PhotometricCurveInspectionRecord::query()->findOrFail($created['id'])->recorded_at->microsecond);

        // An edit cannot move the timestamp either, whether or not it sends one, and
        // the stored value stays put even though the clock has advanced.
        $this->travelTo('2026-09-01 08:00:00');

        $edited = $this->putJsonAs($operator, self::BASE."/{$created['id']}", [
            ...$this->measurements(),
            'voltage' => '221.0',
            'recorded_at' => '1999-01-01 00:00:00',
        ])->assertOk()->json('data');

        $this->assertSame('2026-08-21 10:29:00', $edited['recorded_at']);
        $this->assertSame('221.0', $edited['voltage']);

        $untouched = $this->putJsonAs($operator, self::BASE."/{$created['id']}", $this->measurements())->assertOk()->json('data');
        $this->assertSame('2026-08-21 10:29:00', $untouched['recorded_at']);

        // Reading the record again must not move the timestamp: it is a stored value,
        // never a volatile one computed at read time.
        $first = $this->getJsonAs($operator, self::BASE."/{$created['id']}")->json('data.recorded_at');
        $second = $this->getJsonAs($operator, self::BASE."/{$created['id']}")->json('data.recorded_at');
        $this->assertSame('2026-08-21 10:29:00', $first);
        $this->assertSame($first, $second);

        $this->travelBack();
    }

    public function test_validation_rejects_probe_precision_range_and_duplicate_equipment(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-VAL');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('EQ-VAL');
        $base = [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ];

        $this->postJsonAs($operator, self::BASE, [])->assertJsonValidationErrors([
            'sample_id', 'equipment_system_id', 'equipment_ids', 'probe',
            'c0_180', 'c30_210', 'c60_240', 'c90_270',
            'test_distance', 'peak_luminous_intensity', 'luminous_flux',
            'voltage', 'current', 'power', 'power_factor', 'frequency',
        ]);

        $this->postJsonAs($operator, self::BASE, [...$base, 'probe' => 'middle_field'])->assertJsonValidationErrors('probe');
        $this->postJsonAs($operator, self::BASE, [...$base, 'c0_180' => '60.25'])->assertJsonValidationErrors('c0_180');
        $this->postJsonAs($operator, self::BASE, [...$base, 'c0_180' => '-0.1'])->assertJsonValidationErrors('c0_180');
        $this->postJsonAs($operator, self::BASE, [...$base, 'test_distance' => '26.00001'])->assertJsonValidationErrors('test_distance');
        $this->postJsonAs($operator, self::BASE, [...$base, 'power_factor' => '1.0001'])->assertJsonValidationErrors('power_factor');
        $this->postJsonAs($operator, self::BASE, [...$base, 'power_factor' => '-0.0001'])->assertJsonValidationErrors('power_factor');
        $this->postJsonAs($operator, self::BASE, [...$base, 'power_factor' => '1.0000'])->assertCreated();
        $this->postJsonAs($operator, self::BASE, [...$base, 'frequency' => '50.5'])->assertJsonValidationErrors('frequency');
        $this->postJsonAs($operator, self::BASE, [...$base, 'frequency' => -1])->assertJsonValidationErrors('frequency');
        // A float would already have lost the scale before it reached the column.
        $this->postJsonAs($operator, self::BASE, [...$base, 'current' => 0.1189])->assertJsonValidationErrors('current');
        $this->postJsonAs($operator, self::BASE, [...$base, 'current' => '1e-2'])->assertJsonValidationErrors('current');
        $this->postJsonAs($operator, self::BASE, [...$base, 'current' => '.5'])->assertJsonValidationErrors('current');
        $this->postJsonAs($operator, self::BASE, [...$base, 'remark' => str_repeat('a', 2001)])->assertJsonValidationErrors('remark');
        $this->postJsonAs($operator, self::BASE, [...$base, 'equipment_ids' => [$equipment->id, $equipment->id]])
            ->assertJsonValidationErrors('equipment_ids.0');
    }

    public function test_a_new_record_requires_a_live_sample_and_an_active_system(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-NEW');
        $equipment = $this->equipment('EQ-NEW');
        $disabled = $this->system('sys-off', status: 'disabled');

        $this->postJsonAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $disabled->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ])->assertJsonValidationErrors('equipment_system_id');

        $this->postJsonAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ])->assertJsonValidationErrors('equipment_system_id');
    }

    public function test_snapshots_survive_renaming_disabling_and_deleting_the_ledger_rows(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-SNAP');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('EQ-SNAP');
        $created = $this->createRecord($operator, [], $sample, $system, [$equipment]);

        $sample->update(['sample_no' => 'S-RENAMED']);
        $system->update(['code' => 'sys-renamed', 'name' => '改名系统', 'status' => 'disabled']);
        $equipment->update(['equipment_no' => 'EQ-RENAMED', 'name' => '改名设备']);

        $shown = $this->getJsonAs($operator, self::BASE."/{$created['id']}")->assertOk()->json('data');
        $this->assertSame('S-SNAP', $shown['sample_no']);
        $this->assertSame('sys-01', $shown['system_code']);
        $this->assertSame('系统1', $shown['system_name']);
        $this->assertSame('EQ-SNAP', $shown['equipment'][0]['equipment_no']);

        // An edit that only corrects a measurement re-declares nothing, so every
        // snapshot survives even though its ledger row has moved on.
        $this->putJsonAs($operator, self::BASE."/{$created['id']}", [...$this->measurements(), 'voltage' => '221.0'])
            ->assertOk()
            ->assertJsonPath('data.sample_no', 'S-SNAP')
            ->assertJsonPath('data.system_code', 'sys-01')
            ->assertJsonPath('data.system_name', '系统1')
            ->assertJsonPath('data.voltage', '221.0')
            ->assertJsonPath('data.equipment.0.equipment_no', 'EQ-SNAP');

        $sample->delete();
        $system->delete();
        $equipment->delete();

        $orphaned = $this->getJsonAs($operator, self::BASE."/{$created['id']}")->assertOk()->json('data');
        $this->assertNull($orphaned['sample_id']);
        $this->assertNull($orphaned['equipment_system_id']);
        $this->assertNull($orphaned['equipment'][0]['equipment_id']);
        $this->assertSame('S-SNAP', $orphaned['sample_no']);
        $this->assertSame('sys-01', $orphaned['system_code']);
        $this->assertSame('EQ-SNAP', $orphaned['equipment'][0]['equipment_no']);

        $this->putJsonAs($operator, self::BASE."/{$created['id']}", $this->measurements())
            ->assertOk()
            ->assertJsonPath('data.sample_no', 'S-SNAP')
            ->assertJsonPath('data.equipment.0.equipment_no', 'EQ-SNAP');
    }

    public function test_an_edit_retains_removes_and_replaces_only_the_equipment_it_names(): void
    {
        $operator = $this->editor();
        $first = $this->equipment('EQ-A');
        $second = $this->equipment('EQ-B');
        $third = $this->equipment('EQ-C');
        $created = $this->createRecord($operator, [], null, null, [$first, $second]);
        $children = collect($created['equipment']);

        $replaced = $this->putJsonAs($operator, self::BASE."/{$created['id']}", [
            ...$this->measurements(),
            'retained_equipment_ids' => [$children->firstWhere('equipment_no', 'EQ-A')['id']],
            'equipment_ids' => [$third->id],
        ])->assertOk()->json('data');

        $this->assertSame(['EQ-A', 'EQ-C'], collect($replaced['equipment'])->pluck('equipment_no')->all());

        // Re-snapshotting a device a retained child already covers would duplicate the
        // pairing the unique index forbids.
        $this->putJsonAs($operator, self::BASE."/{$created['id']}", [
            ...$this->measurements(),
            'equipment_ids' => [$first->id],
        ])->assertJsonValidationErrors('equipment_ids');

        // A child id belonging to another record can never be grafted on.
        $other = $this->createRecord($operator, [], null, null, [$this->equipment('EQ-D')]);
        $this->putJsonAs($operator, self::BASE."/{$created['id']}", [
            ...$this->measurements(),
            'retained_equipment_ids' => [$other['equipment'][0]['id']],
        ])->assertJsonValidationErrors('retained_equipment_ids');

        $this->putJsonAs($operator, self::BASE."/{$created['id']}", [
            ...$this->measurements(),
            'retained_equipment_ids' => [],
        ])->assertJsonValidationErrors('equipment_ids');
    }

    public function test_photos_and_files_are_stored_privately_with_metadata_and_served_only_to_readers(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-MEDIA');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('EQ-MEDIA');
        $photo = UploadedFile::fake()->image('curve.jpg', 40, 40);
        $document = $this->pdf('report.pdf');
        $photoDigest = hash_file('sha256', $photo->getRealPath());

        $created = $this->postAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
            'photos' => [$photo],
            'files' => [$document],
        ])->assertCreated()->json('data');

        $this->assertCount(1, $created['photos']);
        $this->assertCount(1, $created['files']);
        $this->assertSame('curve.jpg', $created['photos'][0]['file_name']);
        $this->assertSame('image/jpeg', $created['photos'][0]['mime_type']);
        $this->assertSame($photoDigest, $created['photos'][0]['sha256']);
        $this->assertGreaterThan(0, $created['photos'][0]['size']);
        $this->assertSame('report.pdf', $created['files'][0]['file_name']);

        // Nothing that could locate the bytes leaves the API.
        $encoded = json_encode($created);
        $this->assertStringNotContainsString('inspection-media', $encoded);
        $this->assertStringNotContainsString('storage/', $encoded);
        $this->assertStringNotContainsString('http', $encoded);

        $photoId = $created['photos'][0]['id'];
        $fileId = $created['files'][0]['id'];
        $recordId = $created['id'];

        Sanctum::actingAs($operator);
        $view = $this->get(self::BASE."/{$recordId}/media/{$photoId}/view")->assertOk();
        $this->assertSame('image/jpeg', $view->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $view->headers->get('Content-Disposition'));

        $download = $this->get(self::BASE."/{$recordId}/media/{$fileId}/download")->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('report.pdf', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'photometric_curve_inspection_records.media.download')->count(),
        );

        // A document is never served inline, and media belonging to another record is
        // reported as missing rather than forbidden.
        $this->get(self::BASE."/{$recordId}/media/{$fileId}/view")->assertNotFound();
        $stranger = $this->createRecord($operator);
        $this->get(self::BASE."/{$stranger['id']}/media/{$photoId}/download")->assertNotFound();
    }

    public function test_media_uploads_are_limited_by_count_size_and_matching_type(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-LIMIT');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('EQ-LIMIT');
        $base = [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ];

        $this->postAs($operator, self::BASE, [
            ...$base,
            'photos' => array_map(fn (int $index): UploadedFile => UploadedFile::fake()->image("p{$index}.jpg", 8, 8), range(1, 11)),
        ])->assertJsonValidationErrors('photos');

        // Real content of an allowed type, over the size limit: only the cap rejects it.
        $this->postAs($operator, self::BASE, [...$base, 'photos' => [UploadedFile::fake()->image('big.jpg', 8, 8)->size(10241)]])
            ->assertJsonValidationErrors('photos.0');

        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->pdf('big.pdf')->size(20481)]])
            ->assertJsonValidationErrors('files.0');

        // A script renamed to look like a photo, and a real photo renamed to look like
        // an executable, both fail: the name and the content have to agree.
        $script = UploadedFile::fake()->createWithContent('payload.jpg', "<?php echo 'x';");
        $this->postAs($operator, self::BASE, [...$base, 'photos' => [$script]])->assertJsonValidationErrors('photos.0');

        $renamed = UploadedFile::fake()->image('real.png', 8, 8);
        $disguised = new UploadedFile($renamed->getRealPath(), 'real.exe', 'image/png', null, true);
        $this->postAs($operator, self::BASE, [...$base, 'photos' => [$disguised]])->assertJsonValidationErrors('photos.0');

        // A document type is not a photo type and vice versa.
        $this->postAs($operator, self::BASE, [...$base, 'photos' => [$this->pdf('r.pdf')]])
            ->assertJsonValidationErrors('photos.0');
        $this->postAs($operator, self::BASE, [...$base, 'files' => [UploadedFile::fake()->image('r.jpg', 8, 8)]])
            ->assertJsonValidationErrors('files.0');

        $this->assertDatabaseCount('media', 0);
        $this->assertDatabaseCount('photometric_curve_inspection_records', 0);
    }

    public function test_an_edit_keeps_media_by_default_and_removes_only_what_it_drops(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-EDIT-MEDIA');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('EQ-EDIT-MEDIA');
        $created = $this->postAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
            'photos' => [UploadedFile::fake()->image('one.jpg', 8, 8), UploadedFile::fake()->image('two.jpg', 8, 8)],
            'files' => [$this->pdf('one.pdf')],
        ])->assertCreated()->json('data');

        $recordId = $created['id'];
        $keptPhoto = $created['photos'][0]['id'];
        $droppedPhoto = $created['photos'][1]['id'];
        $keptFile = $created['files'][0]['id'];
        $droppedPath = Media::query()->findOrFail($droppedPhoto)->getPath();

        // An edit that says nothing about media keeps every attachment.
        $untouched = $this->postAs($operator, self::BASE."/{$recordId}", [...$this->measurements(), '_method' => 'PUT'])
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $untouched['photos']);
        $this->assertCount(1, $untouched['files']);

        $edited = $this->postAs($operator, self::BASE."/{$recordId}", [
            ...$this->measurements(),
            '_method' => 'PUT',
            'retained_media_ids' => [$keptPhoto, $keptFile],
            'photos' => [UploadedFile::fake()->image('three.jpg', 8, 8)],
        ])->assertOk()->json('data');

        $this->assertSame(['one.jpg', 'three.jpg'], collect($edited['photos'])->pluck('file_name')->all());
        $this->assertSame(['one.pdf'], collect($edited['files'])->pluck('file_name')->all());
        $this->assertDatabaseMissing('media', ['id' => $droppedPhoto]);
        $this->assertFileDoesNotExist($droppedPath);

        // Media belonging to another record can never be retained onto this one.
        $other = $this->createRecord($operator);
        $otherMedia = $this->attachPhoto(PhotometricCurveInspectionRecord::query()->findOrFail($other['id']));
        $this->postAs($operator, self::BASE."/{$recordId}", [
            ...$this->measurements(),
            '_method' => 'PUT',
            'retained_media_ids' => [$otherMedia->id],
        ])->assertJsonValidationErrors('retained_media_ids');

        // The refused edit changed nothing.
        $this->assertCount(2, $this->getJsonAs($operator, self::BASE."/{$recordId}")->json('data.photos'));
    }

    public function test_an_edit_that_would_exceed_the_media_limit_is_refused_before_anything_is_written(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-CAP');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('EQ-CAP');
        $created = $this->postAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
            'photos' => array_map(fn (int $i): UploadedFile => UploadedFile::fake()->image("p{$i}.jpg", 8, 8), range(1, 9)),
        ])->assertCreated()->json('data');

        $this->postAs($operator, self::BASE."/{$created['id']}", [
            ...$this->measurements(),
            '_method' => 'PUT',
            'photos' => [UploadedFile::fake()->image('a.jpg', 8, 8), UploadedFile::fake()->image('b.jpg', 8, 8)],
        ])->assertJsonValidationErrors('photos');

        $this->assertDatabaseCount('media', 9);
    }

    public function test_a_document_between_the_photo_and_file_limits_is_accepted_end_to_end(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-BIG');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('EQ-BIG');
        // Comfortably over the 10MB photo limit and under the 20MB file limit, which is
        // the band the media library's own ceiling used to reject with a 500.
        $document = $this->realFile('big.pdf', "%PDF-1.4\n".str_repeat('0', 11 * 1024 * 1024)."\n%%EOF\n");
        $bytes = filesize($document->getPathname());

        $created = $this->postAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
            'files' => [$document],
        ])->assertCreated()->json('data');

        $this->assertCount(1, $created['files']);
        $this->assertSame('big.pdf', $created['files'][0]['file_name']);
        $this->assertSame($bytes, $created['files'][0]['size']);
        $this->assertGreaterThan(10 * 1024 * 1024, $bytes);
        $this->assertLessThan(20 * 1024 * 1024, $bytes);
        // The library ceiling has to clear the largest limit any collection advertises,
        // otherwise a file that passed validation fails during the write instead.
        $this->assertGreaterThanOrEqual(20 * 1024 * 1024, (int) config('media-library.max_file_size'));

        Storage::disk('inspection_media')->assertExists(
            Media::query()->findOrFail($created['files'][0]['id'])->id.'/big.pdf',
        );
    }

    public function test_a_photo_between_the_two_limits_is_still_refused(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-BIG-PHOTO');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('EQ-BIG-PHOTO');

        // Raising the library ceiling to 20MB must not quietly raise the photo limit.
        $this->postAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
            'photos' => [UploadedFile::fake()->image('big.jpg', 8, 8)->size(11 * 1024)],
        ])->assertJsonValidationErrors('photos.0');

        $this->assertDatabaseCount('media', 0);
    }

    public function test_each_document_extension_only_accepts_the_content_that_extension_promises(): void
    {
        $operator = $this->editor();
        $base = $this->mediaPayload('S-TYPES', 'EQ-TYPES');

        // Genuine files of every accepted document type go through.
        foreach ([
            $this->realFile('report.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n"),
            $this->realFile('rows.csv', "编号,数值\nXPD-S-001,60.2\n"),
            $this->realFile('legacy.doc', $this->compoundDocumentBytes('WordDocument')),
            $this->realFile('legacy.xls', $this->compoundDocumentBytes('Workbook')),
            $this->realFile('bundle.zip', $this->zipBytes(['notes.txt' => 'plain'])),
            $this->ooxml('sheet.xlsx', 'xlsx'),
            $this->ooxml('doc.docx', 'docx'),
        ] as $accepted) {
            $this->postAs($operator, self::BASE, [...$base, 'files' => [$accepted]])
                ->assertCreated();
        }

        // A generic archive wearing an OOXML name is the case an extension list and a
        // content-type list checked independently cannot tell apart.
        $genericZip = $this->zipBytes(['holiday.jpg' => 'not a document']);
        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->realFile('report.docx', $genericZip)]])
            ->assertJsonValidationErrors('files.0');
        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->realFile('report.xlsx', $genericZip)]])
            ->assertJsonValidationErrors('files.0');

        // Declaring the right content type without carrying the body is not enough,
        // and neither is carrying the body without declaring it.
        $this->postAs($operator, self::BASE, [
            ...$base,
            'files' => [$this->realFile('claimed.docx', $this->zipBytes([
                '[Content_Types].xml' => '<Types><Override ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            ]))],
        ])->assertJsonValidationErrors('files.0');

        $this->postAs($operator, self::BASE, [
            ...$base,
            'files' => [$this->realFile('bodyonly.docx', $this->zipBytes(['word/document.xml' => '<w:document/>']))],
        ])->assertJsonValidationErrors('files.0');

        // One real OOXML document may not wear the other one's extension.
        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->renamed($this->ooxml('doc.docx', 'docx'), 'doc.xlsx')]])
            ->assertJsonValidationErrors('files.0');

        // Old Office, CSV and PDF are each pinned to their own content as well.
        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->realFile('fake.pdf', 'just text')]])
            ->assertJsonValidationErrors('files.0');
        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->realFile('fake.xls', $genericZip)]])
            ->assertJsonValidationErrors('files.0');
        // A bare OLE container is not a Word document, and a workbook is not one either.
        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->realFile('empty.doc', $this->compoundDocumentBytes())]])
            ->assertJsonValidationErrors('files.0');
        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->realFile('sheet.doc', $this->compoundDocumentBytes('Workbook'))]])
            ->assertJsonValidationErrors('files.0');
        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->realFile('text.doc', 'just text')]])
            ->assertJsonValidationErrors('files.0');
        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->realFile('script.csv', "<?php echo 'x';")]])
            ->assertJsonValidationErrors('files.0');
        // The check runs one way on purpose. An OOXML package really is a ZIP, so
        // storing one under a `.zip` name is honest and allowed; what is refused is the
        // other direction, a plain archive claiming to be a document.
        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->renamed($this->ooxml('sheet.xlsx', 'xlsx'), 'sheet.zip')]])
            ->assertCreated();

        // An extension the collection does not list at all is refused on the name.
        $this->postAs($operator, self::BASE, [...$base, 'files' => [$this->realFile('payload.exe', 'MZ')]])
            ->assertJsonValidationErrors('files.0');
    }

    public function test_photo_extensions_are_pinned_to_their_own_image_content(): void
    {
        $operator = $this->editor();
        $base = $this->mediaPayload('S-IMG', 'EQ-IMG');

        $this->postAs($operator, self::BASE, [...$base, 'photos' => [UploadedFile::fake()->image('ok.jpg', 8, 8)]])
            ->assertCreated();

        // A PNG named .jpg is the mismatch the per-extension map is there to catch.
        $png = UploadedFile::fake()->image('real.png', 8, 8);
        $this->postAs($operator, self::BASE, [...$base, 'photos' => [$this->renamed($png, 'real.jpg')]])
            ->assertJsonValidationErrors('photos.0');

        $this->postAs($operator, self::BASE, [...$base, 'photos' => [$this->realFile('payload.jpg', "<?php echo 'x';")]])
            ->assertJsonValidationErrors('photos.0');
        $this->postAs($operator, self::BASE, [...$base, 'photos' => [$this->realFile('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')]])
            ->assertJsonValidationErrors('photos.0');
    }

    public function test_the_collections_accept_exactly_what_the_request_rules_allow(): void
    {
        $library = app(InspectionMediaLibrary::class);
        $record = new PhotometricCurveInspectionRecord;
        $record->registerMediaCollections();

        // If the registered collection and the request rules ever drift, a file that
        // passes validation starts failing inside the library instead, which surfaces
        // as a 500 rather than a 422.
        foreach ([PhotometricCurveInspectionRecord::PHOTO_COLLECTION, PhotometricCurveInspectionRecord::FILE_COLLECTION] as $collection) {
            $registered = collect($record->mediaCollections)->firstWhere('name', $collection);

            $this->assertNotNull($registered);
            $this->assertSame(PhotometricCurveInspectionRecord::MEDIA_DISK, $registered->diskName);
            $this->assertEqualsCanonicalizing($library->acceptedMimeTypes($collection), $registered->acceptsMimeTypes);
        }

        $this->assertContains('application/x-ole-storage', $library->acceptedMimeTypes(PhotometricCurveInspectionRecord::FILE_COLLECTION));
        $this->assertSame(
            ['pdf', 'xls', 'doc', 'csv', 'zip', 'xlsx', 'docx'],
            $library->acceptedExtensions(PhotometricCurveInspectionRecord::FILE_COLLECTION),
        );
        $this->assertSame(
            ['jpg', 'jpeg', 'png', 'webp'],
            $library->acceptedExtensions(PhotometricCurveInspectionRecord::PHOTO_COLLECTION),
        );
    }

    public function test_failure_cleanup_cannot_reach_files_this_request_did_not_create(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-SCOPE');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('EQ-SCOPE');
        $created = $this->postAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
            'photos' => [UploadedFile::fake()->image('kept.jpg', 8, 8)],
        ])->assertCreated()->json('data');
        $keptPath = Media::query()->findOrFail($created['photos'][0]['id'])->getPath();

        // What a concurrent request's in-flight upload looks like from outside its
        // transaction: bytes on the disk under an id no committed row claims. Nothing
        // this request does may touch it.
        $inFlightId = (string) (Media::query()->max('id') + 5);
        Storage::disk('inspection_media')->put($inFlightId.'/other-request.jpg', 'concurrent bytes');

        $attempts = 0;
        Event::listen('eloquent.created: '.Media::class, function () use (&$attempts): void {
            $attempts++;

            if ($attempts > 1) {
                throw new RuntimeException('storage exploded');
            }
        });

        $this->withoutExceptionHandling([RuntimeException::class]);
        $this->postAs($operator, self::BASE."/{$created['id']}", [
            ...$this->measurements(),
            '_method' => 'PUT',
            'photos' => [UploadedFile::fake()->image('lost-one.jpg', 8, 8), UploadedFile::fake()->image('lost-two.jpg', 8, 8)],
        ])->assertStatus(500);

        // The failed request cleaned up after itself and nothing else.
        Storage::disk('inspection_media')->assertExists($inFlightId.'/other-request.jpg');
        $this->assertFileExists($keptPath);
        $this->assertDatabaseCount('media', 1);
        $this->assertCount(1, $this->getJsonAs($operator, self::BASE."/{$created['id']}")->json('data.photos'));
        $this->assertCount(2, Storage::disk('inspection_media')->directories());
    }

    public function test_a_multipart_edit_can_clear_a_retained_list_a_form_body_cannot_express(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-CLEAR');
        $system = $this->system('sys-01');
        $first = $this->equipment('EQ-CLEAR-A');
        $second = $this->equipment('EQ-CLEAR-B');
        $created = $this->postAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$first->id],
            ...$this->measurements(),
            'photos' => [UploadedFile::fake()->image('drop-me.jpg', 8, 8)],
        ])->assertCreated()->json('data');

        // A multipart body cannot send an empty array, so the editor sends the field as
        // an empty string; it has to mean "keep nothing", not "keep everything".
        $cleared = $this->postAs($operator, self::BASE."/{$created['id']}", [
            ...$this->measurements(),
            '_method' => 'PUT',
            'retained_equipment_ids' => '',
            'equipment_ids' => [$second->id],
            'retained_media_ids' => '',
        ])->assertOk()->json('data');

        $this->assertSame(['EQ-CLEAR-B'], collect($cleared['equipment'])->pluck('equipment_no')->all());
        $this->assertSame([], $cleared['photos']);
        $this->assertDatabaseCount('media', 0);

        // A single retained id still arrives as a scalar and is read as a one-item list.
        $childId = $cleared['equipment'][0]['id'];
        $kept = $this->postAs($operator, self::BASE."/{$created['id']}", [
            ...$this->measurements(),
            '_method' => 'PUT',
            'retained_equipment_ids' => (string) $childId,
        ])->assertOk()->json('data');

        $this->assertSame([$childId], collect($kept['equipment'])->pluck('id')->all());
    }

    public function test_a_failed_write_leaves_no_new_files_and_no_partial_record(): void
    {
        $operator = $this->editor();
        $sample = $this->sample('S-FAIL');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('EQ-FAIL');
        $payload = [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ];
        $created = $this->postAs($operator, self::BASE, [
            ...$payload,
            'photos' => [UploadedFile::fake()->image('kept.jpg', 8, 8)],
        ])->assertCreated()->json('data');
        $keptPath = Media::query()->findOrFail($created['photos'][0]['id'])->getPath();

        // The second attachment of the next request fails part way through writing, so
        // one file has already landed on the disk and one has not.
        $attempts = 0;
        Event::listen('eloquent.created: '.Media::class, function () use (&$attempts): void {
            $attempts++;

            if ($attempts > 1) {
                throw new RuntimeException('storage exploded');
            }
        });

        $this->withoutExceptionHandling([RuntimeException::class]);
        $response = $this->postAs($operator, self::BASE."/{$created['id']}", [
            ...$this->measurements(),
            '_method' => 'PUT',
            'voltage' => '199.9',
            'photos' => [UploadedFile::fake()->image('lost-one.jpg', 8, 8), UploadedFile::fake()->image('lost-two.jpg', 8, 8)],
        ]);

        $response->assertStatus(500);

        // The record, its equipment and its previous attachment are exactly as before,
        // and nothing the failed request wrote survives on the private disk.
        $unchanged = $this->getJsonAs($operator, self::BASE."/{$created['id']}")->assertOk()->json('data');
        $this->assertSame('220.8', $unchanged['voltage']);
        $this->assertCount(1, $unchanged['photos']);
        $this->assertSame('kept.jpg', $unchanged['photos'][0]['file_name']);
        $this->assertFileExists($keptPath);
        $this->assertDatabaseCount('media', 1);
        $this->assertCount(1, Storage::disk('inspection_media')->directories());
    }

    public function test_deleting_a_record_removes_its_children_and_media_and_records_the_audit_entry(): void
    {
        $operator = $this->userWithPermissions([
            'photometric_curve_inspection_records.create',
            'photometric_curve_inspection_records.read',
            'photometric_curve_inspection_records.delete',
        ]);
        $sample = $this->sample('S-DELETE');
        $system = $this->system('sys-01');
        $equipment = $this->equipment('EQ-DELETE');
        $created = $this->postAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
            'photos' => [UploadedFile::fake()->image('gone.jpg', 8, 8)],
        ])->assertCreated()->json('data');

        $this->deleteJsonAs($operator, self::BASE."/{$created['id']}")->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertDatabaseCount('photometric_curve_inspection_records', 0);
        $this->assertDatabaseCount('photometric_curve_inspection_equipment', 0);
        $this->assertDatabaseCount('media', 0);
        $this->assertSame(1, AuditLog::query()->where('action', 'photometric_curve_inspection_records.delete')->count());
    }

    public function test_the_record_list_filters_and_orders_deterministically(): void
    {
        $operator = $this->editor();
        $far = $this->createRecord($operator, ['probe' => 'far_field'], $this->sample('SAMPLE-ONE'), $this->system('sys-alpha'), null, '2026-08-01 09:00:00');
        $near = $this->createRecord($operator, ['probe' => 'near_field'], $this->sample('SAMPLE-TWO'), $this->system('sys-beta'), null, '2026-08-10 09:00:00');
        $newest = $this->createRecord($operator, ['probe' => 'near_field'], $this->sample('SAMPLE-THREE'), $this->system('sys-gamma'), null, '2026-08-10 09:00:00');

        $ordered = $this->getJsonAs($operator, self::BASE)->assertOk()->json('data.*.id');
        $this->assertSame([$newest['id'], $near['id'], $far['id']], $ordered);

        $this->assertSame([$near['id']], $this->getJsonAs($operator, self::BASE.'?search=SAMPLE-TWO')->json('data.*.id'));
        $this->assertSame([$far['id']], $this->getJsonAs($operator, self::BASE.'?search=sys-alpha')->json('data.*.id'));
        $this->assertSame([$far['id']], $this->getJsonAs($operator, self::BASE.'?probe=far_field')->json('data.*.id'));
        $this->assertSame([$newest['id'], $near['id']], $this->getJsonAs($operator, self::BASE.'?date_from=2026-08-10')->json('data.*.id'));
        $this->assertSame([$far['id']], $this->getJsonAs($operator, self::BASE.'?date_to=2026-08-01')->json('data.*.id'));
        $this->assertSame(3, $this->getJsonAs($operator, self::BASE.'?per_page=2')->json('meta.total'));
        $this->assertCount(2, $this->getJsonAs($operator, self::BASE.'?per_page=2')->json('data'));
    }

    public function test_the_record_list_search_reaches_the_used_equipment_snapshots(): void
    {
        $operator = $this->editor();
        $created = $this->createRecord($operator, [], null, null, [$this->equipment('EQ-SEARCH', name: '光度计')]);
        $this->createRecord($operator, [], $this->sample('S-OTHER'), $this->system('sys-other'), [$this->equipment('EQ-OTHER')]);

        $this->assertSame([$created['id']], $this->getJsonAs($operator, self::BASE.'?search=EQ-SEARCH')->json('data.*.id'));
        // Percent-encoded exactly as a browser sends it, so the snapshot name really is
        // searchable and not only its ASCII number.
        $this->assertSame([$created['id']], $this->getJsonAs($operator, self::BASE.'?search='.urlencode('光度计'))->json('data.*.id'));
    }

    public function test_the_flattened_equipment_ledger_lists_one_row_per_association(): void
    {
        $operator = $this->editor();
        $first = $this->equipment('XPD-S-001');
        $second = $this->equipment('XPD-S-002', serialNo: 'G117422CJ6361112');
        $created = $this->createRecord($operator, [], null, null, [$first, $second], '2026-08-21 10:29:00');

        $rows = $this->getJsonAs($operator, self::BASE.'/equipment')->assertOk()->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame($created['id'], $rows[0]['inspection_record_id']);
        $this->assertSame('XPD-S-001', $rows[0]['equipment_no']);
        $this->assertSame($first->id, $rows[0]['equipment_id']);
        $this->assertSame('2026-08-21 10:29:00', $rows[0]['recorded_at']);
        $this->assertSame($operator->name, $rows[0]['operator_name']);
        $this->assertSame('G117422CJ6361112', $rows[1]['serial_no']);
        $this->assertSame('2027-03-01', $rows[1]['next_calibration_date']);

        $this->assertSame(1, $this->getJsonAs($operator, self::BASE.'/equipment?search=XPD-S-002')->json('meta.total'));
        $this->assertSame(2, $this->getJsonAs($operator, self::BASE."/equipment?inspection_record_id={$created['id']}")->json('meta.total'));
        $this->assertSame(1, $this->getJsonAs($operator, self::BASE."/equipment?equipment_id={$first->id}")->json('meta.total'));
        $this->assertSame(2, $this->getJsonAs($operator, self::BASE.'/equipment?date_from=2026-08-21')->json('meta.total'));
        $this->assertSame(0, $this->getJsonAs($operator, self::BASE.'/equipment?date_to=2026-08-20')->json('meta.total'));

        // A deleted ledger row leaves the association visible with its snapshot intact.
        $first->delete();
        $orphaned = $this->getJsonAs($operator, self::BASE.'/equipment?search=XPD-S-001')->json('data');
        $this->assertCount(1, $orphaned);
        $this->assertNull($orphaned[0]['equipment_id']);
        $this->assertSame('XPD-S-001', $orphaned[0]['equipment_no']);
    }

    public function test_the_two_inspection_workflows_keep_separate_ledgers(): void
    {
        $operator = $this->userWithPermissions([
            'photometric_curve_inspection_records.create',
            'photometric_curve_inspection_records.read',
            'integrating_sphere_inspection_records.read',
        ]);
        $this->createRecord($operator, [], null, null, [$this->equipment('EQ-SEPARATE')]);

        $this->assertSame(1, $this->getJsonAs($operator, self::BASE.'/equipment')->json('meta.total'));
        $this->assertSame(0, $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/equipment')->json('meta.total'));
        $this->assertSame(0, $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records')->json('meta.total'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<int, Equipment>  $devices
     * @return array<string, mixed>
     */
    private function createRecord(
        User $operator,
        array $overrides = [],
        ?Sample $sample = null,
        ?EquipmentSystem $system = null,
        ?array $devices = null,
        ?string $recordedAt = null,
    ): array {
        $sample ??= Sample::query()->first() ?? $this->sample('26010058874-1-1/1');
        $system ??= EquipmentSystem::query()->where('status', 'active')->first() ?? $this->system('sys-01');
        $devices ??= [Equipment::query()->first() ?? $this->equipment('XPD-S-001')];

        // The recorded time is server owned, so a test that needs a particular one
        // moves the clock rather than sending a value the API would ignore.
        if ($recordedAt !== null) {
            $this->travelTo($recordedAt);
        }

        $created = $this->postJsonAs($operator, self::BASE, [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => collect($devices)->pluck('id')->all(),
            ...$this->measurements(),
            ...$overrides,
        ])->assertCreated()->json('data');

        if ($recordedAt !== null) {
            $this->travelBack();
        }

        return $created;
    }

    /**
     * The workbook row, with the angles from its input-method sheet so the derived
     * average has a genuine tie to round.
     *
     * @return array<string, mixed>
     */
    private function measurements(): array
    {
        return [
            'c0_180' => '60.2',
            'c30_210' => '60.3',
            'c60_240' => '64.5',
            'c90_270' => '60.8',
            'probe' => 'far_field',
            'test_distance' => '26.0000',
            'peak_luminous_intensity' => '221.0',
            'luminous_flux' => '1674.0',
            'voltage' => '220.8',
            'current' => '0.1189',
            'power' => '14.2400',
            'power_factor' => '0.5422',
            'frequency' => 50,
        ];
    }

    /**
     * @param  array<int, Equipment>  $devices
     */
    private function record(Sample $sample, array $devices): PhotometricCurveInspectionRecord
    {
        $record = PhotometricCurveInspectionRecord::query()->create([
            'sample_id' => $sample->id,
            'sample_no' => $sample->sample_no,
            'recorded_at' => '2026-08-21 10:29:00',
            ...collect($this->measurements())->all(),
        ]);

        foreach ($devices as $device) {
            $record->equipment()->create([
                'equipment_id' => $device->id,
                'equipment_no' => $device->equipment_no,
                'equipment_name' => $device->name,
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
                'serial_no' => $device->serial_no,
                'next_calibration_date' => $device->next_calibration_date,
            ]);
        }

        return $record->fresh(['equipment']);
    }

    /**
     * The create payload every media-typing case reuses, so each assertion is only
     * about the file it is testing.
     *
     * @return array<string, mixed>
     */
    private function mediaPayload(string $sampleNo, string $equipmentNo): array
    {
        return [
            'sample_id' => $this->sample($sampleNo)->id,
            'equipment_system_id' => $this->system('sys-01')->id,
            'equipment_ids' => [$this->equipment($equipmentNo)->id],
            ...$this->measurements(),
        ];
    }

    /**
     * An upload backed by real bytes on disk. The API sniffs content rather than
     * trusting the upload header, so a fake whose type is merely declared proves
     * nothing about what the validation actually accepts.
     */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('inspection-media-', true).'-'.$name;
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /** The same bytes under a different name, which is the whole attack being tested. */
    private function renamed(UploadedFile $file, string $name): UploadedFile
    {
        return $this->realFile($name, (string) file_get_contents($file->getPathname()));
    }

    /**
     * A genuine, minimal OOXML package: the declared content type and the body part
     * that a generic archive renamed `.docx` or `.xlsx` does not have.
     */
    private function ooxml(string $name, string $kind): UploadedFile
    {
        $parts = $kind === 'docx'
            ? [
                'content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
                'part' => 'word/document.xml',
                'body' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
            ]
            : [
                'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
                'part' => 'xl/workbook.xml',
                'body' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets/></workbook>',
            ];

        return $this->realFile($name, $this->zipBytes([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Override PartName="/'.$parts['part'].'" ContentType="'.$parts['content_type'].'"/></Types>',
            $parts['part'] => $parts['body'],
        ]));
    }

    /**
     * Builds a real archive in memory rather than committing a binary fixture.
     *
     * @param  array<string, string>  $entries
     */
    private function zipBytes(array $entries): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('inspection-zip-', true).'.zip';
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $archive->addFromString($name, $content);
        }

        $archive->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * A minimal OLE compound file: the header both `.doc` and `.xls` begin with, plus
     * the UTF-16LE directory name of the one stream that tells the two formats apart.
     * Passing no stream builds the bare container a sniffer cannot classify.
     */
    private function compoundDocumentBytes(?string $stream = null): string
    {
        $bytes = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 4096);

        if ($stream === null) {
            return $bytes;
        }

        return $bytes.mb_convert_encoding($stream, 'UTF-16LE', 'UTF-8').str_repeat("\x00", 512);
    }

    /**
     * A fake upload whose bytes really are a PDF: the API sniffs content rather than
     * trusting the upload header, so a zero-byte fake would be rejected as empty.
     */
    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
        );
    }

    private function attachPhoto(PhotometricCurveInspectionRecord $record): Media
    {
        return $record
            ->addMedia(UploadedFile::fake()->image('seed.jpg', 8, 8))
            ->withCustomProperties(['original_file_name' => 'seed.jpg', 'mime_type' => 'image/jpeg', 'size' => 1, 'sha256' => str_repeat('0', 64)])
            ->toMediaCollection(PhotometricCurveInspectionRecord::PHOTO_COLLECTION);
    }

    private function system(string $code = 'sys-01', string $name = '系统1', string $status = 'active'): EquipmentSystem
    {
        return EquipmentSystem::query()->create(['code' => $code, 'name' => $name, 'status' => $status]);
    }

    private function equipment(
        string $equipmentNo,
        string $name = '智能交流测试专用电源',
        string $serialNo = 'G117422CJ1361114',
        string $manufacturer = '杭州远方',
        string $model = 'DPS1060-V200',
    ): Equipment {
        return Equipment::query()->create([
            'equipment_no' => $equipmentNo,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'model' => $model,
            'serial_no' => $serialNo,
            'next_calibration_date' => '2027-03-01',
            'status' => 'active',
        ]);
    }

    private function sample(string $sampleNo): Sample
    {
        $order = TestOrder::query()->first()
            ?? TestOrder::query()->create([
                'order_no' => 'ORDER-CURVE',
                'contract_no' => 'CONTRACT-CURVE',
                'order_date' => '2026-08-21',
                'urgency' => 'normal',
                'client_company' => '中山市样品客户',
                'sample_status' => 'received',
            ]);

        return Sample::query()->create([
            'test_order_id' => $order->id,
            'sample_no' => $sampleNo,
            'sample_name' => '灯具',
            'model' => 'LD-1',
            'quantity' => 1,
            'status' => 'pending',
            'current_holder' => '样品室',
        ]);
    }

    private function editor(): User
    {
        return $this->userWithPermissions([
            'photometric_curve_inspection_records.create',
            'photometric_curve_inspection_records.read',
            'photometric_curve_inspection_records.update',
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_photometric_curve_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function getJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postJsonAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->postJson($uri, $data);
    }

    /**
     * Multipart, the way the editor sends a record that carries attachments.
     *
     * @param  array<string, mixed>  $data
     */
    private function postAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->post($uri, $data, ['Accept' => 'application/json']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function putJsonAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->putJson($uri, $data);
    }

    private function deleteJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->deleteJson($uri);
    }
}
