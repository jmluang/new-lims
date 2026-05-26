<?php

namespace App\Enums;

enum PermissionAction: string
{
    case Create = 'create';
    case Read = 'read';
    case Update = 'update';
    case Delete = 'delete';
    case Export = 'export';
    case Hide = 'hide';
    case Print = 'print';
}
