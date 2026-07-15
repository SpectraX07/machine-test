<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUserRolesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'role_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
        ]);

        $this->forge->addKey(['user_id', 'role_id'], true);
        $this->forge->addKey('role_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('role_id', 'roles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('user_roles');
    }

    public function down()
    {
        $this->forge->dropTable('user_roles');
    }
}
