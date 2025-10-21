<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceModel extends Model
{
    //
    protected $table = 't_invoice';
    protected $fillable = ['t_order_id'];
}
