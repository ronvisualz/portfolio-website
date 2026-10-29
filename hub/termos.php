<?php
require __DIR__ . '/_legal.php';
$c = LEGAL_CONTACT;
legal_page('Termos de Utilização', <<<HTML
<p>A <b>topper.</b> (topperhub.xyz) é a plataforma <b>interna</b> de gestão da Topper Digital (leads, negócios, tarefas, agenda e reuniões). Ao entrar, aceita estes termos.</p>

<h2>Quem pode usar</h2>
<p>Só a equipa da Topper Digital, com uma conta criada por um administrador. A conta é pessoal: não partilhe a palavra-passe, as chaves de acesso nem os links privados (por exemplo, o link da agenda).</p>

<h2>Utilização</h2>
<ul>
  <li>Use a plataforma apenas para o trabalho da Topper Digital e de acordo com as permissões que lhe foram dadas.</li>
  <li>Ligar a conta Google ou o Google Calendar é opcional e pode ser desligado a qualquer momento em Perfil › Ligações.</li>
  <li>As reuniões criadas pela plataforma com Google Meet seguem também os <a href="https://policies.google.com/terms" rel="noopener">termos da Google</a>.</li>
</ul>

<h2>Dados</h2>
<p>O tratamento de dados pessoais está descrito na <a href="/privacidade">Política de Privacidade</a>. Nenhum dado é vendido nem partilhado com terceiros.</p>

<h2>Disponibilidade e alterações</h2>
<p>A plataforma é fornecida tal como está, para uso interno; podemos mudá-la, suspendê-la ou alterar estes termos, com a data de atualização indicada acima. A conta pode ser desativada quando a pessoa deixa a empresa ou em caso de uso indevido.</p>

<h2>Lei aplicável e contacto</h2>
<p>Aplica-se a lei portuguesa. Dúvidas: <a href="mailto:$c">$c</a>.</p>
HTML);
