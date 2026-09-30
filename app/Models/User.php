<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'modules' => 'array',
        ];
    }

    public function isSuperAdmin(): bool
    {
        $name = mb_strtolower(trim((string) $this->name));
        $names = array_map(fn (string $candidate): string => mb_strtolower(trim($candidate), 'UTF-8'), config('workspace.super_admin_names', []));

        return in_array($name, $names, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->isSuperAdmin();
    }

    public function canAccessModule(string $module): bool
    {
        if ($this->isSuperAdmin() || $this->role !== 'admin') {
            return true;
        }

        return $this->modules === null || in_array($module, $this->modules, true);
    }

    public function accessibleModules(): array
    {
        return collect(config('workspace.modules'))->keys()
            ->filter(fn (string $module): bool => $this->canAccessModule($module))
            ->values()->all();
    }
}
