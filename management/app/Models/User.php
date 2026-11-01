<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'is_admin',
    'role',
    'must_rotate_password',
    'bootstrap_password_issued_at',
    'password_rotated_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'must_rotate_password' => 'boolean',
            'bootstrap_password_issued_at' => 'datetime',
            'password' => 'hashed',
            'password_rotated_at' => 'datetime',
        ];
    }

    public function canAdministerLicenses(): bool
    {
        return $this->is_admin || $this->role === 'license_admin';
    }
}
