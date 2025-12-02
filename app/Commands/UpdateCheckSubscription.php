<?php

namespace App\Commands;

use App\Libraries\LicenseHelper;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\BaseCommand;
use App\Models\AdminLicenseModel;

class UpdateInviteStatus extends BaseCommand
{
    protected $group = 'cron';
    protected $name = 'cron:update-user-subscription';
    protected $description = 'Update subscription status based on subscription expiry date';

    public function run(array $params = [])
    {
        $date = date('Y-m-d');
        $licenseModel = new AdminLicenseModel();

        // Fetch all active licenses which are expired
        $expiredLicenses = $licenseModel
            ->where('license_expiry <', $date)
            ->where('license_status !=', AdminLicenseModel::EXPIRED)
            ->findAll();

        foreach ($expiredLicenses as $license) {

            $license_id = $license['id'];
            $admin_id   = $license['admin_id'];

            // Generate and reason per admin
            $reason       = LicenseHelper::generateRevocationReason('expired');

            // Update each license
            $licenseModel->update($license_id, [
                'license_status'    => AdminLicenseModel::EXPIRED,
                'revocation_reason' => $reason,
                'revoked_at'        => date('Y-m-d H:i:s')
            ]);
        }

        CLI::write("License Auto-Expiry executed at {$date}");
    }
}
