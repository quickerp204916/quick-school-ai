<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Receipt {{ $p->number }}</title>
<link href="{{ asset('css/app.css') }}" rel="stylesheet"><style>body{padding:16px;background:#fff}</style></head>
<body>@include('fees::payments.receipt-body')<p style="text-align:center"><button onclick="window.print()" class="btn">Print or save as PDF</button></p></body></html>
