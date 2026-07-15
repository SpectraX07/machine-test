<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateJwtDenylistTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'jti' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('jti');
        $this->forge->addKey('expires_at');
        $this->forge->addKey('user_id');
        $this->forge->createTable('jwt_denylist');
    }

    public function down()
    {
        $this->forge->dropTable('jwt_denylist');
    }
}
