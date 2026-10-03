<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  {!! csrf_meta() !!}
  <title>{{ config('app.name') }}</title>
  @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
  <div id="app" data-props="{{ json_encode(['name' => config('app.name')]) }}"></div>
</body>
</html>