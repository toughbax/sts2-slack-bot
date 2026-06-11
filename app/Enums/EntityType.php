<?php

namespace App\Enums;

enum EntityType: string
{
    case Card = 'card';
    case Relic = 'relic';
    case Potion = 'potion';
    case Enemy = 'enemy';
    case Event = 'event';
    case Enchant = 'enchant';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
