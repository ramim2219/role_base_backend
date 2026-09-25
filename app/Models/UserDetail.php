<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'image',
        'user_id',
        'full_name',
        'contact',
        'present_address',
        'permanent_address',
        'father_name',
        'mother_name',
        'date_of_birth',
        'marital_status',
        'spouse_name',
        'nid_number',
        'gender',
        'birth_number',
        'religion',
        'nationality',
        'blood_group',
        'joining_date',
        'resignation_date',
        'emergency_contact_name',
        'emergency_contact_relation',
        'emergency_contact_phone',
        'emergency_contact_address',
        'created_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'joining_date' => 'date',
        'resignation_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}