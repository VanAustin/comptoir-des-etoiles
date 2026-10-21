<?php

namespace App\Enum;

enum DeviceRole: string
{
    case Till = 'caisse';
    case Kitchen = 'cuisine';
    case Display = 'affichage';
}
