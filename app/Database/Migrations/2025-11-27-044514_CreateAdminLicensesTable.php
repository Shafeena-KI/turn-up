<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAdminLicensesTable extends Migration
{
    public function up()
    {
        // ---------- Create admin_licenses table ----------
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'admin_id' => [
                'type'       => 'INT', // admin_users.admin_id is INT
            ],
            'license_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'license_expiry' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'license_status' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 1,
            ],
            'revocation_reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'granted_at' => [
                'type'    => 'TIMESTAMP',
                'null' => true,
            ],
            'revoked_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'granted_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'revoked_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'created_at' => [
                'type'    => 'TIMESTAMP',
                'null' => true,
            ],
            'updated_at' => [
                'type'    => 'TIMESTAMP',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('admin_id');
        $this->forge->addKey('license_status');
        $this->forge->addKey('license_expiry');

        $this->forge->addForeignKey('admin_id', 'admin_users', 'admin_id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('admin_licenses', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down()
    {
        // Drop new table
        $this->forge->dropTable('admin_licenses', true);
    }
}
