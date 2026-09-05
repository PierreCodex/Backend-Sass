<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RolesSistema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rol del negocio. Vive en la BD del TENANT: un rol que inventa el dueño es
 * dato suyo. Los tres de sistema (`dueno`, `admin`, `profesional`) se siembran
 * al provisionar desde `App\Support\RolesSistema`.
 *
 * Se usa para resolver `clave` → `rol_id` al dar de alta un empleado, para
 * contar el cupo del plan sin salir de la base del negocio, y desde
 * `/api/roles` para que el dueño arme los suyos.
 */
class Rol extends Model
{
    protected $table = 'roles';

    protected $fillable = ['nombre', 'clave', 'sistema', 'permisos', 'solo_propios', 'editado_at'];

    protected $casts = [
        'sistema' => 'boolean',
        'solo_propios' => 'boolean',
        'permisos' => 'array',
        'editado_at' => 'datetime',
    ];

    /**
     * Las cuentas que llevan este rol.
     *
     * Cuelga de `usuarios` y no de `profesionales` desde la separación del
     * 2026-09-04: el rol es de quien ENTRA al panel, no de quien presta los
     * servicios. Un barbero sin cuenta no tiene rol, y no le hace falta.
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class);
    }

    /** @return array<int, string> Las claves de los roles de sistema. */
    public static function clavesDeSistema(): array
    {
        return ['admin_general', 'admin_local', 'profesional'];
    }

    /**
     * La matriz con los 14 módulos, `null` donde no hay acceso.
     *
     * El JSON guardado solo trae las claves con permiso (el preset de
     * Profesional tiene cinco de catorce). Rellenar los huecos aquí, y no en
     * el cliente, es lo que evita que la lista de módulos exista en dos sitios.
     *
     * @return array<string, string|null>
     */
    public function permisosCompletos(): array
    {
        $vacia = array_fill_keys(RolesSistema::MODULOS, null);

        // Solo los módulos conocidos: si un rol quedó con una clave de un
        // módulo retirado, se ignora en vez de filtrarse al API.
        return array_merge($vacia, array_intersect_key($this->permisos ?? [], $vacia));
    }

    /**
     * El rol de administrador general no se toca por ninguna vía.
     *
     * Quitarle un permiso lo dejaría fuera de su propia facturación, y
     * dárselo a otro fabricaría un segundo superusuario. Cambiar de titular es
     * una operación de soporte, no una casilla del formulario.
     */
    public function esAdminGeneral(): bool
    {
        return $this->clave === 'admin_general';
    }

    public function editable(): bool
    {
        return ! $this->esAdminGeneral();
    }

    /**
     * Los tres de sistema no se borran ni renombrándolos: son el suelo sobre
     * el que se apoyan el provisioning y el cupo del plan.
     */
    public function borrable(): bool
    {
        return ! $this->sistema;
    }

    public function duplicable(): bool
    {
        return ! $this->esAdminGeneral();
    }
}
