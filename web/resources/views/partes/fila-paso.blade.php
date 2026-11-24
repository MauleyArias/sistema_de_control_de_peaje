<tr>
    <td>@if($p['imagen_url'])<a href="{{ $p['imagen_url'] }}" target="_blank"><img class="mini" src="{{ $p['imagen_url'] }}" alt="evidencia" loading="lazy"></a>@else — @endif</td>
    <td class="placa">{{ $p['placa'] }}</td>
    <td>{{ $p['categoria'] }}</td>
    <td class="num">{{ $p['ejes'] }}</td>
    <td class="num">{{ $p['pasajeros'] }}</td>
    <td class="num">Bs {{ number_format($p['monto'], 2) }}</td>
    <td><span class="chip {{ $p['estado'] }}">{{ str_replace('_', ' ', $p['estado']) }}</span></td>
    <td>{{ $p['caseta'] }}</td>
    <td class="suave">{{ $p['hora'] }}</td>
</tr>
