<dialog class="confirm-dialog" data-plan-change-dialog aria-labelledby="plan-change-dialog-title">
    <h2 id="plan-change-dialog-title">{{ __('プラン変更の確認') }}</h2>
    <p>{{ __('以下の内容をご確認のうえ、プラン変更を確定してください。') }}</p>
    <p data-plan-change-note></p>
    <div class="confirm-dialog-actions">
        <button class="secondary-action" type="button" data-plan-change-cancel>{{ __('キャンセル') }}</button>
        <button class="primary-action" type="button" data-plan-change-confirm>{{ __('変更を確定する') }}</button>
    </div>
</dialog>
