<?php

namespace App\Services\Equipment;

use App\Models\Equipment;

class EquipmentPlacementResolver
{
    public const LEGACY_DEVICE_SITE = '曹一天宏';

    public const LEGACY_DEVICE_ROOM = '样品室';

    /**
     * @return array{location_site: ?string, location_room: ?string}
     */
    public function resolve(Equipment $equipment, ?string $site = null, ?string $room = null): array
    {
        $equipment->loadMissing('location.parent');
        $location = $equipment->location;
        $parent = $location?->parent;

        return [
            'location_site' => $site ?: ($parent?->name ?? $location?->name),
            'location_room' => $room ?: ($parent ? $location?->name : $equipment->legacy_placement),
        ];
    }

    /**
     * @return array{location_site: string, location_room: string}
     */
    public function legacyDeviceDefaults(): array
    {
        return [
            'location_site' => self::LEGACY_DEVICE_SITE,
            'location_room' => self::LEGACY_DEVICE_ROOM,
        ];
    }
}
