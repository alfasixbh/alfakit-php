<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class CartCounter extends Component
{
    public int $count = 0;

    public string $label = 'Cart';

    #[On('cart-updated')]
    public function refresh(int $count): void
    {
        $this->count = $count;
    }

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): View
    {
        return view('livewire.cart-counter');
    }
}
