<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientsContactPersonModel extends Model
{
    //
    protected $table = 't_clients_contact_person';
    protected $fillable = ['client_id', 'name', 'designation', 'mobile', 'email'];
}
