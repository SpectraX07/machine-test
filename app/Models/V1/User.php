<?php

namespace App\Models\V1;

use CodeIgniter\Model;

class User extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'object';
    protected $useSoftDeletes = true;
    protected $protectFields = true;
    protected $allowedFields = ['name', 'email', 'phone', 'status', 'password'];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [];
    protected array $castHandlers = [];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    protected $validationRules = [];
    protected $validationMessages = [];
    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert = ['hashPassword'];
    protected $beforeUpdate = ['hashPassword'];

    /** Columns safe to return in API responses (never password). */
    public const PUBLIC_FIELDS = 'id, name, email, phone, status, created_at, updated_at';

    protected function hashPassword(array $data): array
    {
        if (isset($data['data']['password'])) {
            $data['data']['password'] = password_hash(
                $data['data']['password'],
                PASSWORD_DEFAULT
            );
        }

        return $data;
    }

    public function findByEmail(string $email): ?object
    {
        return $this->where('email', $email)->first();
    }

    public function toPublic(object $user): object
    {
        unset($user->password, $user->deleted_at);

        return $user;
    }

    /**
     * Keyset (cursor) pagination — O(log n) seek via primary key, not OFFSET.
     * Fetches limit+1 rows to detect has_more without a COUNT(*).
     *
     * @return list<object>
     */
    public function paginateByCursor(?int $cursor, int $perPage): array
    {
        $builder = $this->builder()
            ->select(self::PUBLIC_FIELDS)
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC')
            ->limit($perPage + 1);

        if ($cursor !== null && $cursor > 0) {
            $builder->where('id >', $cursor);
        }

        return $builder->get()->getResult();
    }
}
