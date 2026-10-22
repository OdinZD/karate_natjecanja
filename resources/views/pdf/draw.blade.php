@extends('pdf.layout', ['title' => __('Draw').' - '.$competition->name])

@section('content')
    @foreach ($categories as $category)
        <div @class(['page-break' => ! $loop->last])>
            @include('pdf.bracket-section', ['competition' => $competition, 'category' => $category])
        </div>
    @endforeach
@endsection
