@props(['icon', 'title', 'description', 'action' => null, 'withBackground' => false])

@if ($withBackground)
    <div class="bg-white dark:bg-gray-800/50 dark:border-gray-800/30 rounded-[0.70rem] overflow-hidden border border-gray-950/5 shadow-none p-8 transition duration-300 ease-in-out hover:shadow-md">
        <div class="text-center my-10">
            {{ $icon }}
            <h3 class="text-gray-900 dark:text-white text-xl font-semibold my-4">
                {{ $title }}
            </h3>
            <p class="text-gray-700 dark:text-gray-300 text-lg font-medium">
                {{ $description }}
            </p>
            @isset($action)
                <div class="mt-6">
                    {{ $action }}
                </div>
            @endisset
        </div>
    </div>
@else
    <div class="text-center my-10">
        {{ $icon }}
        <h3 class="text-gray-900 dark:text-white text-xl font-semibold my-4">
            {{ $title }}
        </h3>
        <p class="text-gray-700 dark:text-gray-300 text-lg font-medium">
            {{ $description }}
        </p>
        @isset($action)
            <div class="mt-6">
                {{ $action }}
            </div>
        @endisset
    </div>
@endif
