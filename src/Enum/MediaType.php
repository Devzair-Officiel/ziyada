<?php

declare(strict_types=1);

namespace App\Enum;

enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Video => 'Vidéo',
        };
    }
}
