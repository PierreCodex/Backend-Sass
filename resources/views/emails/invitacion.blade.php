{{--
    «Te dieron acceso, crea tu contraseña».

    Tres cosas que este correo tiene que hacer, en este orden:

    1. **Decir quién invita y desde qué negocio.** Es lo que separa un correo
       legítimo de uno que parece phishing: alguien recibe, sin haberlo pedido,
       un enlace para «crear una contraseña». Si no reconoce el nombre de su
       jefe y el de su trabajo en la primera línea, no pulsa — y hace bien.
    2. **Repetir el correo con el que va a entrar.** Es su usuario, y puede no
       ser el que él habría elegido: se lo dio de alta otra persona.
    3. **Un solo botón.**
--}}
<x-correo.layout :color="$color" :titulo="$titulo" :preheader="$preheader">

<p style="margin:0 0 8px 0; font-size:15px; color:#6b7280;">Hola {{ $nombre }},</p>

<h1 style="margin:0 0 20px 0; font-size:22px; line-height:30px; font-weight:600; color:#111827;">
    Ya tienes acceso a {{ $negocio }}
</h1>

<p style="margin:0 0 20px 0;">
    @if ($invitadoPor)
        <strong>{{ $invitadoPor }}</strong> te dio acceso al panel de
        <strong>{{ $negocio }}</strong>, donde vas a poder ver tu agenda y tus citas.
    @else
        Te dieron acceso al panel de <strong>{{ $negocio }}</strong>, donde vas a
        poder ver tu agenda y tus citas.
    @endif
</p>

<p style="margin:0 0 28px 0;">
    Para entrar solo falta que elijas tu contraseña. Tu usuario es
    <strong style="color:#111827;">{{ $email }}</strong>.
</p>

{{-- Botón en tabla, no un <a> con padding: Outlook no respeta el padding de un
     enlace y el botón llega como texto suelto. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px 0;">
    <tr>
        <td align="center" style="border-radius:8px; background-color:{{ $color }};">
            <a href="{{ $url }}"
               style="display:inline-block; padding:14px 28px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px;">
                Crear mi contraseña
            </a>
        </td>
    </tr>
</table>

<p style="margin:0 0 24px 0; font-size:14px; line-height:22px; color:#6b7280;">
    El enlace vence en {{ $dias }} días. Si se te pasa, pídele a
    {{ $invitadoPor ?: 'quien te dio de alta' }} que te lo reenvíe desde el panel.
</p>

<hr style="border:none; border-top:1px solid #e5e7eb; margin:0 0 20px 0;">

{{-- El enlace en texto: hay clientes que no pintan el botón, y quien lea el
     correo en una terminal o con el HTML desactivado se quedaría sin salida. --}}
<p style="margin:0; font-size:13px; line-height:20px; color:#6b7280;">
    ¿No funciona el botón? Copia esta dirección en tu navegador:<br>
    <span style="color:#4b5563; word-break:break-all;">{{ $url }}</span>
</p>

<x-slot:pie>
    Si no esperabas este correo, ignóralo: sin crear la contraseña, nadie puede entrar con esa cuenta.
</x-slot:pie>

</x-correo.layout>
