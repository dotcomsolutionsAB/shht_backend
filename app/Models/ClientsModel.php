<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientsModel extends Model
{
    //
    protected $table = 't_clients';
    protected $fillable = ['name', 'category', 'sub_category', 'tags', 'city', 'state', 'rm'];
}
