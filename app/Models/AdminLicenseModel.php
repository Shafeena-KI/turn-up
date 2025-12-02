<?php
namespace App\Models;

use CodeIgniter\Model;

class AdminLicenseModel extends Model
{
    protected $table = 'admin_licenses';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'admin_id', 'license_hash', 'license_expiry', 'license_status',
        'revocation_reason', 'granted_at', 'revoked_at',
        'granted_by', 'revoked_by'
    ];

    protected array $casts = [
        'license_status' => 'integer'
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    const ACTIVE = 1;
    const EXPIRED = 2;
    const REVOKED = 3;
    const INACTIVE = 4;


    public function getActiveLicense($adminId)
    {
        return $this->where('admin_id', $adminId)
                   ->where('license_status', self::ACTIVE)
                   ->first();
    }

    public function getCurrentLicense($adminId)
    {
        return $this->where('admin_id', $adminId)
                   ->orderBy('created_at', 'DESC')
                   ->first() ?? null;
    }
}