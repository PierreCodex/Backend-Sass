<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class BusinessCategory extends Model
{
    use CentralConnection;

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
