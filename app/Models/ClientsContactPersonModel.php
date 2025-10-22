<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientsContactPersonModel extends Model
{
    //
    protected $table = 't_clients_contact_person';
    protected $fillable = ['client', 'name', 'designation', 'mobile', 'email'];

    public function clientRef()
    {
        // foreign key 'client' -> t_clients.id
        return $this->belongsTo(ClientsModel::class, 'client', 'id');
    }
}
