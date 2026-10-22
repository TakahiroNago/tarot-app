<div class="toast-region" data-toast-region aria-live="polite">
    <p class="toast{{ ($type ?? 'status') === 'error' ? ' is-error' : '' }}" data-auto-dismiss role="{{ ($type ?? 'status') === 'error' ? 'alert' : 'status' }}">{{ $message }}</p>
</div>
