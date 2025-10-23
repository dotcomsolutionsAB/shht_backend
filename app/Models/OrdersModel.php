<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdersModel extends Model
{
    //
    protected $table = 't_orders';
    protected $fillable = ['client', 'client_contact_person', 'so_no', 'so_date', 'order_no', 'order_date', 'invoice', 'status', 'initiated_by', 'checked_by', 'dispatched_by', 'drive_link'];

    public function clientRef()
    {
        return $this->belongsTo(ClientsModel::class, 'client', 'id');
    }

    public function contactRef()
    {
        return $this->belongsTo(ClientsContactPersonModel::class, 'client_contact_person', 'id');
    }

    public function initiatedByRef()
    {
        return $this->belongsTo(User::class, 'initiated_by', 'id');
    }

    public function checkedByRef()
    {
        return $this->belongsTo(User::class, 'checked_by', 'id');
    }

    public function dispatchedByRef()
    {
        return $this->belongsTo(User::class, 'dispatched_by', 'id');
    }
}
