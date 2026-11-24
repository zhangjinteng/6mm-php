<?php
namespace SixMm\Shared\UserActions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserAccountChangeOrder extends Model
{
    use SoftDeletes;
    protected $table = 'user_account_change_order';
    protected $guarded = ['id'];
    const STATUS_PENDING = 0;
    const STATUS_SUCCESS = 1;
    const STATUS_FAILED = 2;
    const TYPE_ADMIN_RECHARGE = 'admin_recharge';
    const TYPE_ADMIN_DEDUCT = 'admin_deduct';
}
