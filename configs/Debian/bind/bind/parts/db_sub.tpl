; sub [{SUBDOMAIN_NAME}] entry BEGIN.
$ORIGIN {SUBDOMAIN_NAME}.
; sub SUBDOMAIN_entries BEGIN.
@       IN    {IP_TYPE}    {DOMAIN_IP}
; sub OPTIONAL entries BEGIN.
www     IN    CNAME    @
ftp     IN    {IP_TYPE}    {DOMAIN_IP}
; sub OPTIONAL entries ENDING.
; sub SUBDOMAIN entries ENDING.
; sub MAIL entry BEGIN.
@       IN    MX    10 mail
@       IN    TXT   "v=spf1 include:{DOMAIN_NAME} -all"
mail    IN    {BASE_SERVER_IP_TYPE}    {BASE_SERVER_IP}
imap    IN    {BASE_SERVER_IP_TYPE}    {BASE_SERVER_IP}
pop     IN    {BASE_SERVER_IP_TYPE}    {BASE_SERVER_IP}
pop3    IN    {BASE_SERVER_IP_TYPE}    {BASE_SERVER_IP}
relay   IN    {BASE_SERVER_IP_TYPE}    {BASE_SERVER_IP}
smtp    IN    {BASE_SERVER_IP_TYPE}    {BASE_SERVER_IP}
; sub MAIL entry ENDING.
; sub [{SUBDOMAIN_NAME}] entry ENDING.
