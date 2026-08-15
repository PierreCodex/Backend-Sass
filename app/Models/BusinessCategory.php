<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessCategory extends Model
{
    protected $fillable = [
        'nombre',
        'slug',
        'icono',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
