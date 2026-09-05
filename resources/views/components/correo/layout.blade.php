{{--
    El armazón de todos nuestros correos.

    HTML de correo, no de web: tablas y estilos EN LÍNEA. Gmail descarta buena
    parte de un `<style>` y Outlook renderiza con el motor de Word, que ignora
    flex, grid y casi todo lo que uno querría usar. Lo que se ve aquí feo es lo
    que llega bien.

    Sin imágenes externas: la mayoría de los clientes las bloquean por defecto,
    así que un diseño que dependa de ellas llega roto a la primera impresión —
    y este correo suele ser la primera vez que alguien ve el producto.

    Se diseña en CLARO. Quien tenga el cliente en oscuro lo verá invertido por
    su propio cliente, y pelear contra eso produce texto ilegible en la mitad de
    los casos.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $titulo ?? '' }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f7; -webkit-font-smoothing:antialiased;">

{{-- Preheader: el texto que la bandeja enseña junto al asunto. Sin esto,
     muchos clientes muestran el primer texto que encuentren, que suele ser
     «Hola» y nada más. --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">
    {{ $preheader ?? '' }}
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background-color:#f4f4f7;">
    <tr>
        <td align="center" style="padding:32px 12px;">

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:560px; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb;">

                {{-- Franja de color. Es todo el «logo» que necesita: una imagen
                     bloqueada dejaría la cabecera vacía. --}}
                <tr>
                    <td style="height:6px; background-color:{{ $color ?? '#4f46e5' }}; line-height:6px; font-size:0;">&nbsp;</td>
                </tr>

                <tr>
                    <td style="padding:36px 36px 28px 36px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#111827; font-size:16px; line-height:26px;">
                        {{ $slot }}
                    </td>
                </tr>

            </table>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
                <tr>
                    <td style="padding:20px 36px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#6b7280; font-size:13px; line-height:20px; text-align:center;">
                        {{ $pie ?? '' }}
                    </td>
                </tr>
            </table>

        </td>
    </tr>
</table>

</body>
</html>
