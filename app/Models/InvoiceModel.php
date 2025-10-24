<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceModel extends Model
{
    //
    protected $table = 't_invoice';
    protected $fillable = ['order', 'invoice_number', 'invoice_date', 'billed_by'];

    // Relationships
    public function orderRef()
    {
        return $this->belongsTo(OrdersModel::class, 'order');
    }

    public function billedByRef()
    {
        return $this->belongsTo(User::class, 'billed_by');
    }
}
