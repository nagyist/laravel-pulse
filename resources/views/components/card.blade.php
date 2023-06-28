<div {{ $attributes->merge([
    'class' => 'flex flex-col p-6 bg-white rounded-lg border border-gray-200 '.match($width ?? 'full') {
        'full' => 'col-span-12',
        '1/2' => 'col-span-6',
        '1/3' => 'col-span-4',
        '1/4' => 'col-span-3',
    }
]) }}>
    <div class="flex items-center justify-between">
        {{ $title }}
    </div>
    <div class="flex-1 mt-6">
        {{ $slot }}
    </div>
</div>
