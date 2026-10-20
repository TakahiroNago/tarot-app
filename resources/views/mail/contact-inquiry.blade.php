<h1>{{ __('お問い合わせを受け付けました') }}</h1>
<dl>
    <dt>{{ __('お名前') }}</dt>
    <dd>{{ $name }}</dd>
    <dt>{{ __('返信先メールアドレス') }}</dt>
    <dd>{{ $email }}</dd>
    <dt>{{ __('件名') }}</dt>
    <dd>{{ $subject }}</dd>
    <dt>{{ __('お問い合わせ内容') }}</dt>
    <dd style="white-space: pre-wrap;">{{ $inquiryMessage }}</dd>
</dl>
