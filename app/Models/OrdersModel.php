<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdersModel extends Model
{
    //
    protected $table = 't_orders';
    protected $fillable = ['client', 'client_contact_person', 'so_no', 'so_date', 'order_no', 'order_date', 'invoice', 'status', 'initiated_by', 'checked_by', 'dispatched_by', 'drive_link'];
}
