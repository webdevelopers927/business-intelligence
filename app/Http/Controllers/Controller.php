<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Queue a toast for the next page render (shared as flash.toast).
     *
     * @param  'success'|'error'|'info'  $type
     */
    protected function toast(string $message, string $type = 'success'): void
    {
        session()->flash('toast', ['type' => $type, 'message' => $message, 'id' => (string) str()->uuid()]);
    }
}
