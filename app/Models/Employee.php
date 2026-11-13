<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PtkpStatus;
use App\Enums\TerminationType;
use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\TracksAuthors;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Karyawan. user_id opsional menautkan ke akun login (satu akun untuk satu karyawan);
 * Absensi, Jadwal, dan Cuti membaca data lewat akun tersebut.
 *
 * Penempatan (cabang, departemen, jabatan, atasan, grade) diubah lewat formulir atau lewat
 * riwayat mutasi/promosi (EmployeeMovement). Status kerja mengikuti kontrak terbaru (EmployeeContract).
 * Karyawan tidak disaring per cabang aktif (bukan data transaksi); saringan cabang ada di daftar.
 */
class Employee extends Model
{
    use HasFactory, RecordsActivity, SoftDeletes, TracksAuthors;

    protected $fillable = [
        'code', 'attendance_pin', 'name', 'gender', 'user_id', 'branch_id', 'department_id', 'position_id', 'supervisor_id', 'grade', 'base_salary', 'overtime_eligible',
        'employment_type', 'identity_number', 'birth_place', 'birth_date', 'religion', 'marital_status', 'blood_type',
        'tax_id', 'ptkp_status', 'bpjs_health_number', 'bpjs_employment_number',
        'bank_name', 'bank_account_number', 'bank_account_name',
        'join_date', 'end_date', 'phone', 'email', 'address',
        'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
        'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'gender' => Gender::class,
            'marital_status' => MaritalStatus::class,
            'ptkp_status' => PtkpStatus::class,
            'termination_type' => TerminationType::class,
            'birth_date' => 'date',
            'join_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
            'base_salary' => 'decimal:2',
            'overtime_eligible' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_id')->withTrashed();
    }

    /** Bawahan langsung. */
    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_id');
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(EmployeeFamilyMember::class)->orderBy('id');
    }

    public function educations(): HasMany
    {
        return $this->hasMany(EmployeeEducation::class)->orderBy('start_year')->orderBy('id');
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(EmployeeExperience::class)->orderBy('start_date')->orderBy('id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class)->latest('start_date')->latest('id');
    }

    /** Kontrak terbaru (dasar status kerja dan pengingat kontrak habis). */
    public function currentContract(): HasOne
    {
        return $this->hasOne(EmployeeContract::class)->ofMany(['start_date' => 'max', 'id' => 'max']);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(EmployeeMovement::class)->latest('effective_date')->latest('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class)->orderBy('type')->latest('id');
    }

    public function offboardings(): HasMany
    {
        return $this->hasMany(EmployeeOffboarding::class)->latest('id');
    }

    /** Akun login atasan langsung (untuk tahap persetujuan "Atasan langsung"), atau null. */
    public function supervisorUser(): ?User
    {
        $userId = $this->supervisor()->value('user_id');

        return $userId ? User::query()->find($userId) : null;
    }

    /** Aset tetap yang dipegang (penanggung jawab). */
    public function fixedAssets(): HasMany
    {
        return $this->hasMany(FixedAsset::class)->withoutGlobalScope(BranchScope::class);
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
    }

    /** @param array{cari?:?string,departemen?:?int,jabatan?:?int,jenis?:?string,cabang?:?int,status?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, $kata) => $q->where(fn (Builder $w) => $w
                ->where('code', 'like', "%{$kata}%")->orWhere('name', 'like', "%{$kata}%")
                ->orWhere('phone', 'like', "%{$kata}%")->orWhere('email', 'like', "%{$kata}%")))
            ->when($f['departemen'] ?? null, fn (Builder $q, $v) => $q->where('department_id', $v))
            ->when($f['jabatan'] ?? null, fn (Builder $q, $v) => $q->where('position_id', $v))
            ->when($f['jenis'] ?? null, fn (Builder $q, $v) => $q->where('employment_type', $v))
            ->when($f['cabang'] ?? null, fn (Builder $q, $v) => $q->where('branch_id', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('is_active', $v === 'aktif'));
    }

    protected function activityLogName(): string
    {
        return 'sdm';
    }

    protected function activityLogLabel(): string
    {
        return 'Karyawan';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['code', 'name', 'user_id', 'branch_id', 'department_id', 'position_id', 'supervisor_id', 'grade', 'employment_type', 'join_date', 'end_date', 'termination_type', 'is_active'];
    }
}
