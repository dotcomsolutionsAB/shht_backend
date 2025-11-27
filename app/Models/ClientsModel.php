<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientsModel extends Model
{
    //
    protected $table = 't_clients';
    protected $fillable = ['name', 'category', 'sub_category', 'tags', 'city', 'state', 'rm', 'sales_person'];

    public function categoryRef()
    {
        return $this->belongsTo(CategoryModel::class, 'category', 'id');
    }

    public function subCategoryRef()
    {
        return $this->belongsTo(SubCategoryModel::class, 'sub_category', 'id');
    }

    public function rmRef()
    {
        // RM stored as users.id
        return $this->belongsTo(User::class, 'rm', 'id');
    }

    public function salesRef()
    {
        // Sales Person stored as users.id
        return $this->belongsTo(User::class, 'rm', 'id');
    }

    public function contactPersons()
    {
        return $this->hasMany(ClientsContactPersonModel::class, 'client', 'id');
    }
}
