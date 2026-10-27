<?php

namespace App\Models;

use App\Enums\SalesInquiryStatus;
use Database\Factories\SalesInquiryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A "Contact sales" submission from the public pricing page.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $company
 * @property string $team_size
 * @property string|null $plan
 * @property string|null $message
 * @property SalesInquiryStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'company', 'team_size', 'plan', 'message', 'status'])]
class SalesInquiry extends Model
{
    /** @use HasFactory<SalesInquiryFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'new',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SalesInquiryStatus::class,
        ];
    }
}
