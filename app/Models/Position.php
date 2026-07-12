<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use HasFactory, Auditable, SoftDeletes;

    protected $fillable = ['title', 'salary_grade'];

    public function employees(): HasMany
    {
        return $this->hasMany(EmployeeProfile::class);
    }
}
