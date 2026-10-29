<?php
require __DIR__ . '/_legal.php';
$c = LEGAL_CONTACT;
legal_page('Política de Privacidade', <<<HTML
<p>A <b>topper.</b> (topperhub.xyz) é a plataforma <b>interna</b> da Topper Digital: só a usa a equipa da empresa, com contas criadas por um administrador. Não é um serviço aberto ao público.</p>

<h2>Que dados usamos</h2>
<ul>
  <li><b>Conta na plataforma:</b> nome, email de trabalho e a palavra-passe (guardada cifrada) ou as chaves de acesso do aparelho.</li>
  <li><b>«Entrar com Google»:</b> usamos <b>apenas o endereço de email e o identificador da conta Google</b>, para saber quem está a entrar. Não lemos contactos, ficheiros, emails nem outros dados da conta Google.</li>
  <li><b>Google Calendar (opcional, ligado por cada pessoa):</b> usado <b>apenas para criar e sincronizar as reuniões da própria pessoa</b> (reuniões, gravações, visitas e prazos com hora marcados na plataforma, incluindo o link do Google Meet) e, se a pessoa o escolher, para ver os seus períodos «ocupado/livre» — sem títulos nem detalhes — ao marcar reuniões sem conflitos.</li>
  <li><b>Registo de entradas:</b> data, método de entrada e tipo de aparelho, que cada pessoa vê no seu Perfil.</li>
</ul>

<h2>O que não fazemos</h2>
<p><b>Nenhum dado é vendido nem partilhado</b> com terceiros, nem usado para publicidade. Os dados recebidos das APIs da Google são usados só para as funções acima e cumprem a <a href="https://developers.google.com/terms/api-services-user-data-policy" rel="noopener">Política de Dados do Utilizador dos Serviços de API da Google</a>, incluindo os requisitos de Uso Limitado.</p>

<h2>Como guardamos</h2>
<p>Os dados ficam nos servidores da plataforma. O acesso ao Google Calendar fica cifrado no servidor e nunca é mostrado na página. Só a própria pessoa e os administradores da Topper Digital acedem aos dados da plataforma, conforme as permissões de cada um.</p>

<h2>Desligar e apagar</h2>
<p>Cada pessoa pode desligar a conta Google e o Google Calendar a qualquer momento em <b>Perfil › Ligações</b> (o acesso é revogado na Google e os eventos criados pela plataforma são retirados), ou em <a href="https://myaccount.google.com/permissions" rel="noopener">myaccount.google.com/permissions</a>. Quando uma pessoa sai da empresa, a conta é desativada e deixa de poder entrar. Para pedir acesso, correção ou eliminação dos seus dados, escreva para o contacto abaixo.</p>

<h2>Contacto</h2>
<p>Topper Digital — <a href="mailto:$c">$c</a></p>
HTML);
