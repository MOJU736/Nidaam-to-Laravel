<?php

namespace App\Http\Controllers;

class VendorController extends PartyController
{
    protected function side(): string
    {
        return 'ap';
    }
}
