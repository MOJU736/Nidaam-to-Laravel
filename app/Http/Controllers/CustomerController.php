<?php

namespace App\Http\Controllers;

class CustomerController extends PartyController
{
    protected function side(): string
    {
        return 'ar';
    }
}
