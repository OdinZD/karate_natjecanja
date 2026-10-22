@extends('pdf.layout', ['title' => $category->name.' - '.$competition->name])

@section('content')
    @include('pdf.bracket-section', ['competition' => $competition, 'category' => $category])
@endsection
