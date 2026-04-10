<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'host',
        'status',
        'max_clients',
        'current_clients',
    ];

    protected function casts(): array
    {
        return [
            'max_clients' => 'integer',
            'current_clients' => 'integer',
        ];
    }
}
