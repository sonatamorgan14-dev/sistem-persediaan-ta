<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RequestDetail extends Model
{
    use HasFactory;

    // Kunci auto-increment, sesuai $table->id() di migrasi request_details.
    protected $primaryKey = 'request_detail_id';

    protected $fillable = [
        'request_id',
        'item_id',
        'item_proposal_id',
        'quantity_requested',
        'quantity_approved',
    ];

    public function request()
    {
        return $this->belongsTo(Request::class, 'request_id', 'request_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }

    public function itemProposal()
    {
        return $this->belongsTo(ItemProposal::class, 'item_proposal_id', 'item_proposal_id');
    }
}
