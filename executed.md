# VP-DEMO-001 — Auditoria e planejamento do ambiente demonstrativo VetorPet

**Data:** 05/10/2026. **Executor:** Codex. **Status:** auditoria estática concluída; implantação não autorizada e não iniciada.

## 1. Escopo, evidências e limitações

Executadas somente leituras do repositório autorizado e consulta à documentação pública. Única alteração desta tarefa: este relatório em `executed.md`, mecanismo já utilizado pelo projeto. O relatório anterior foi preservado ao final. Alterações preexistentes em controllers, models, telas e testes de assinatura/Controle de Pragas foram preservadas e consideradas como estado local, sem assumir que estejam publicadas.

Não houve acesso a banco, dados pessoais, logs operacionais, credenciais, painel administrativo ou containers. Não foram executados Artisan, migrations, seeders, testes, deploy, reinicialização ou alterações de infraestrutura. Os testes Feature usam `RefreshDatabase` e MySQL em `phpunit.xml`; executá-los sem confirmar isolamento violaria o escopo.

A consulta pública a https://vetorpet.com.br falhou na ferramenta de navegação; a avaliação da página inicial usa o código local. Não foi possível confirmar equivalência entre checkout e produção, migrations aplicadas, variáveis efetivas, versão do runtime, topologia do gateway, capacidade do servidor ou serviços compartilhados em operação. Ausência de implementação encontrada significa ausência no material inspecionado, não comprovação sobre sistemas externos.

Fontes principais: `composer.json`, `composer.lock`, `bootstrap/app.php`, `routes/*.php`, `app/Models`, `app/Traits/Tenantable.php`, `app/Models/Scopes/TenantScope.php`, controllers, requests, serviços, `config/*.php`, migrations, factories/seeders, telas React, Dockerfile e testes existentes. Não foram consultados arquivos privados de configuração nem arquivos compactados de publicação.

## 2. Diagnóstico da arquitetura

- Laravel **12.69.2** no lockfile; requisito PHP **^8.3**, imagem Docker **PHP 8.4 FPM**. Inertia Laravel **2.0.27**, Sanctum **4.3.3**, Mercado Pago SDK **3.16.0**, Ziggy **2.6.4**. Frontend declara React 19, TypeScript 5.7, Vite 6, Tailwind 4 e `@react-pdf/renderer` 4.5.1; intervalos declarados não equivalem a versões efetivas de produção.
- Monólito Laravel/Inertia/React com landing page, painel empresarial `/app`, administração `/admin` e API Sanctum `/api`. `bootstrap/app.php` registra web/API pelos argumentos e novamente em `then`; revisar duplicidade antes de adicionar proteção DEMO, validando a tabela efetiva de rotas em ambiente isolado.
- Autenticação web: sessão, login/logout, recuperação de senha e rotas de verificação de e-mail. `User` não implementa `MustVerifyEmail` (import comentado), portanto não presumir verificação obrigatória. Listener de login grava `tenant_id` em sessão. Login web limita cinco tentativas por e-mail/IP, e impede acesso de técnico Pest ao painel. API emite tokens Sanctum, valida usuário ativo e assinatura; não há limiter explícito no login API inspecionado.
- Tenancy: banco compartilhado, coluna `tenant_id` e escopo Eloquent global. **Sem usuário e sem tenant na sessão, `TenantScope` não aplica filtro algum.** A trait permite criação sem preencher tenant se faltar contexto. Várias migrations admitem `tenant_id` nulo. Não há isolamento físico por empresa demonstrado.
- Usuário pertence a um tenant; empresa aponta para proprietário (`owner_user_id`). Papéis: root 99, owner 1 e seller 2. Entretanto, superadmin e middleware administrativo reconhecem **tenant nulo**, não exclusivamente papel root. DEMO jamais pode autenticar usuário com tenant nulo.
- Representantes usam vínculo N:N com regiões (`region_user`); clientes possuem região e responsável. Visibilidade de cliente para vendedor é regional; pedidos usam `user_id`. Models e validações possuem proteções úteis, mas é necessário testar autorização por operação, não apenas escopo.
- Autorização por middleware, métodos como `canManageTeam`/`canManageCatalog`, verificações nos controllers e regras `exists`/`unique` por tenant. Não foi encontrada uma camada geral de policies que garanta automaticamente todas as ações. Alguns FormRequests retornam `authorize() = true`, dependendo das demais camadas.
- Planos e assinatura próprios: `Admin/Plan`, `Period`, `Feature`, `Tenant`, `Payment`, `PlanLimits`, `TenantModuleService`; tipos individual/equipe, trial de 14 dias, ciclos de cobrança e limites. `payments` registra **assinatura SaaS**, não recebimentos de vendas da distribuidora. Pix e consulta de status podem chamar Mercado Pago; webhook valida assinatura e altera pagamentos/licença.
- Infraestrutura no checkout: build Node 22 + PHP-FPM e entrypoint que copia assets. Não foi encontrado Compose do ambiente neste diretório. Cache, sessões e filas possuem defaults em banco; configurações permitem Redis/SQS/S3 e serviços de e-mail. Configuração disponível não comprova utilização efetiva.
- `routes/console.php` contém apenas comando `inspire`; não foi encontrado agendamento de restauração ou classe de job de negócio. Imports de `ShouldQueue` no listener não o tornam assíncrono. Filas e tabelas de jobs existem como infraestrutura genérica. Workers/scheduler externos permanecem não verificados.
- PDF de pedidos é gerado no frontend por React PDF; existem impressão de pedido, relatório de despesas e exportação de dashboard. Não foi encontrada dependência PHP especializada em PDF. PDFs e exportações exigem proteção e identificação DEMO.

## 3. Mapa dos módulos disponíveis

| Recurso | Evidência e alcance para demonstração |
|---|---|
| Clientes B2B | `Customer`, `CustomerController`, requests e API; tipo de estabelecimento, região, responsável e dados comerciais |
| Produtos | `Product`, CRUD web/API; marca, categoria, embalagem, imagem, preço, saldo e mínimo |
| Preços e condições | `CommercialCondition`, `ProductRegionPrice`, `RegionalPriceResolver`; condições por cliente/região/tipo/global e campanhas; não presumir cadastro autônomo de tabelas de preços |
| Representantes | `User` seller, regiões e gestão de equipe condicionada a plano/permissão |
| Pedidos | `Order`, `OrderItem`, controllers web/API e `OrderUpdateService`; preços registrados no item, descontos, comissão, Flex, recorrência e cancelamento |
| Estoque | Saldo `products.quantity`, ajuste transacional com lock e efeitos dos pedidos; **sem tabela de histórico de movimentações identificada** |
| Despesas/financeiro comercial | `Expense`, comprovantes, quilometragem e relatórios; comissões e totais de pedidos; **sem contas a pagar/receber ou conciliação bancária identificadas** |
| Visitas comerciais | `Visit`, agenda/check-in/check-out; distinto das visitas técnicas Pest |
| Relatórios/indicadores | Dashboard, vendas, vendedores, despesas, comissões, inteligência comercial, exportação e PDFs |
| Campanhas/catálogos | Campanhas e catálogo público por token; exposição pública intencional precisa de revisão na DEMO |
| Fornecedores/compras | Nenhum model, migration ou CRUD identificado; não prometer nem gerar 20 registros inexistentes |
| Fiscal/SMS | Nenhum emissor fiscal ou integração SMS identificado; manter bloqueio preventivo de saída |
| VetorPest | Módulo técnico existente e acoplado; excluir integralmente da experiência DEMO |

Não foi encontrado modo DEMO, restauração automática ou factory comercial reutilizável. Há apenas `UserFactory` e `DatabaseSeeder` básico criando usuário de teste. Exemplos sintéticos dos testes servem para compreender regras, não como massa comercial pronta.

## 4. Alternativas de isolamento e recomendação

| Critério | A: tenant na instalação atual | B: instância com banco separado | C: containers próprios e banco separado |
|---|---|---|---|
| Segurança/dados | Baixa para exposição pública; depende de todos os filtros | Boa se serviços e credenciais também separados | Melhor entre as três com rede, volumes e limites próprios |
| Manutenção | Baixa inicialmente; proteções invasivas | Média; configuração independente | Média; imagens reproduzíveis e configuração segregada |
| Custo | Menor custo direto; maior risco operacional | Baixo/médio conforme hospedagem | Baixo/médio em host dedicado à DEMO; recursos limitados |
| Atualização | Acoplada à produção | Release independente | Mesma imagem versionada, release independente |
| Restauração | Arriscada no banco produtivo | Segura com credenciais restritas | Segura com recursos descartáveis segregados |
| Risco à produção | Alto: banco/processos/filas comuns | Residual se host/serviços compartilhados | Residual se mesmo host; menor com VM separada |
| Escala | Compete diretamente com produção | Escala instância e banco | Replica app e amplia pool sob quotas |

**Recomendar C, concretizando também B:** instalação DEMO em containers próprios, banco próprio, chave de aplicação, credenciais, armazenamento, sessões, cache e filas exclusivos. Preferir VM pequena separada da produção. Containers sozinhos não constituem isolamento completo: compartilham kernel/host, e não devem acessar socket Docker, rede, volumes ou segredos produtivos. A documentação oficial descreve essa fronteira em [Docker Engine security](https://docs.docker.com/engine/security/).

Para custo inicial controlado, usar app compartilhado apenas entre visitantes DEMO e um pequeno pool de bancos sintéticos por sessão, com seleção de conexão imposta pelo backend. Um tenant por visitante no banco DEMO custa menos, mas mantém risco de interferência entre visitantes caso falhe o escopo. Portanto, preferir banco por sessão/pool para escrita; a escolha e tamanho do pool precisam ser homologados. Container de app por visitante é isolamento adicional possível, com custo e manutenção superiores.

## 5. Massa fictícia e consistência

Empresa: **Distribuidora Pet Brasil — DEMONSTRAÇÃO**. Base inicial proposta por sessão: 150 clientes, 300 produtos, 8 representantes e 1 usuário de demonstração restrito, 4–6 regiões, 200 pedidos com itens existentes, 100 despesas e visitas comerciais suficientes para indicadores. Não criar fornecedores nem 500 registros de movimentação: essas entidades não foram encontradas. Pode haver 500 **eventos simulados no processo de geração** para reconciliar saldo, documentados no manifesto técnico; não apresentá-los como funcionalidade de histórico disponível.

Janela histórica: seis meses até a data de geração; na auditoria, referência 05/04/2026 a 05/10/2026. Snapshot precisa ser renovado com datas móveis para que os filtros de mês atual continuem mostrando atividade. Incluir clientes sem compra recente, produtos com saldo baixo e pedidos em diferentes estados, respeitando os estados e filtros reais dos controllers.

Ordem futura: plano/ciclo compatíveis com limites → tenant explícito → usuários/regiões/pivôs → clientes → produtos → condições/campanhas/preços especiais → pedidos/itens → despesas/visitas → validação → snapshot. Usar IDs e `tenant_id` explícitos, inclusive no CLI; não depender de autenticação implícita. Não reaproveitar `DatabaseSeeder` básico sem corrigir o contexto, pois usuário sem tenant pode adquirir semântica administrativa.

Gerador determinístico com semente/versionamento, manifestos de contagens e checksum; UUIDs, tokens e identidades de sessão regenerados na clonagem. Nomes curtos com “DEMO”, marcas inventadas, e-mails em domínio `.invalid`, endereços inventados e contatos sem encaminhamento. Documentos são exclusivamente fixtures sintéticas identificadas; números com dígito verificador não garantem inexistência de titular real. Validar compatibilidade com requests e jamais transmiti-los a serviços oficiais. Não copiar, anonimizar ou exportar dados produtivos.

Reconciliar as regras reais, inclusive a convenção de sinal de `discount_amount`: preço especial regional ativo precede condições/campanha; preço de item é snapshot; subtotal é soma dos totais de itens; total = máximo entre zero e total ajustado menos desconto manual; comissão = total × percentual / 100 com arredondamento monetário. Flex depende do saldo disponível e deve reconciliar criação/edição/cancelamento. Saldo final = saldo inicial + ajustes positivos − ajustes negativos − saídas de pedidos + devoluções de cancelamento, segundo os efeitos reais de cada operação. Geração deve validar estoque não negativo e transições efetivas, não apenas inserir totais plausíveis. Pedidos já consomem estoque na criação; não descontar duas vezes ao atribuir estado final. Não inventar recebimentos ou pagamentos comerciais usando `payments` de assinatura.

Critérios: nenhuma FK órfã ou cruzada entre tenants, contagens previstas, saldo/Flex reconciliados, centavos consistentes entre itens e pedidos, responsáveis válidos, datas dentro da janela e KPIs iguais aos cálculos independentes pelos mesmos filtros de status/data. Manter cenários de mínimo de pedido, comissão, preço regional e campanha que possam ser demonstrados sem quebrar validações.

## 6. Acesso público e experiência comercial

Botão **TESTAR VETORPET** em hero/header/CTA encaminha para `demo.vetorpet.com.br` (proposta). GET apresenta entrada; POST protegido por CSRF cria/reserva sessão DEMO. Nenhum cadastro ou senha administrativa pública. Usuário pertence ao banco/tenant daquela reserva e tem apenas permissões comerciais permitidas. Não bastam cookies diferentes sobre o mesmo usuário/base: visitantes ainda alterariam os mesmos registros.

Proposta inicial: sessão com 30 minutos de inatividade e 60 minutos de duração absoluta, quota de ações e banco reservado exclusivamente até expiração. Cookie host-only com nome e chave próprios, HTTPS/Secure/HttpOnly/SameSite, regeneração ao entrar; identificador opaco sem seleção de banco/tenant fornecida pelo cliente. Vínculo servidor: sessão → reserva → conexão → tenant → usuário. Validar vínculo em toda requisição, download e tarefa. Sem login automático por querystring, tokens permanentes ou cookies válidos na produção. Expiração invalida sessão e tokens antes de liberar o recurso.

Comparação: usuário compartilhado é barato, mas inadequado para escrita concorrente; sessão temporária sem base independente só resolve autenticação; tenant por visitante isola logicamente com maior necessidade de auditoria; banco por sessão resolve interferência no banco com maior consumo; base global em leitura é fallback seguro e econômico se não houver capacidade para sessões editáveis.

Abuso: limites por IP e sessão, limite global de reservas, fila de entrada e mensagem de capacidade esgotada, limitação de mutações e exportações, desafio adicional somente sob abuso e teto de duração. Valores iniciais para homologação: 3 entradas/min/IP, 60 consultas/min/sessão, 20 mutações/min/sessão e 2 exportações/min/sessão; ajustar após carga, considerando redes NAT e acessibilidade.

Boas-vindas: identificação “dados fictícios; alterações temporárias; sem efeitos externos”, tempo restante e roteiro: dashboard → cliente/região/representante → produto/preço especial → pedido com 2–3 itens → estoque → comissão/despesas/relatórios. Não prometer fornecedor, emissão fiscal, compras ou financeiro completo. PDFs e impressões devem ter marca d’água DEMONSTRAÇÃO, sem QR de cobrança utilizável.

## 7. Proteções obrigatórias e riscos encontrados

| Achado | Implicação / proteção requerida |
|---|---|
| Escopo sem contexto não filtra | Bloquear execução comercial sem contexto; conferir todas as consultas diretas e `withoutGlobalScopes`; banco DEMO separado é obrigatório |
| Tenant nulo caracteriza administrador | Identidade DEMO sempre com tenant; bloquear `/admin`, gestão de usuários/roles, conta, licença, módulos e configurações sensíveis no backend |
| Papéis owner/seller não formam perfil DEMO granular | Criar política explícita de ações permitidas; não promover visitante a owner para destravar catálogo |
| Pix, status e webhook chamam gateway | Negar rotas DEMO e impedir chamadas também no serviço; gateway simulado local, sem credenciais nem QR cobravel |
| Arquivos no disco público, caminhos como `products`/`company-logos` | Separar volume da produção e namespace por reserva; impedir acesso cruzado, paths arbitrários e execução de arquivos |
| Uploads 2 MB para imagens, 5 MB para comprovantes | Limitar também quantidade, bytes acumulados, corpo HTTP, dimensões e conteúdo; começar DEMO com upload desativado ou fixtures locais |
| Login API e rotas públicas sem limiter explícito identificado | Verificar gateway e definir throttling específico; desabilitar registro, recuperação e emissão livre de tokens na DEMO |
| Listagens/relatórios usam `get()`, API `/alldata` agrega dados | Quotas de registros, intervalo máximo de datas, paginação, timeout e limites de exportação |
| Integrações no navegador | Há ViaCEP nos formulários e links WhatsApp em produtos/landing; substituir consulta por fixture e compartilhar por simulação, usando CSP própria da DEMO |
| Defaults de fila/cache/sessão e serviços configuráveis | Nenhuma conexão/namespace/worker compartilhado; jobs exigem contexto explícito e expiração |
| Migrations alteram dados/configurações e incluem SQL MySQL | Não executar em produção; snapshot ligado à versão do schema; homologar em engine compatível |
| Docker copia diretório inteiro com exclusões mínimas | Revisar contexto e imagem para excluir segredos, arquivos históricos, dumps, ZIPs e APKs fora do escopo |

E-mail externo: bloquear recuperação/convites e usar sink local; não confiar apenas em mailer configurado. WhatsApp/SMS/fiscal/pagamentos/webhooks/APIs sensíveis: negar no backend e por política de saída de rede, sem credenciais externas; funcionalidades inexistentes continuam sem integração. CSP e revisão de links cobrem o navegador, que não é protegido pelo bloqueio de rede do servidor. Comunicação comercial do site principal é separada da simulação operacional DEMO.

Mass assignment: há `$fillable`, porém campos sensíveis como `tenant_id`, `roles` e `status` aparecem em `User`; revisar payloads validados e autorização. Tenant/banco/usuário/permissões nunca vêm do payload visitante. Proteger CSRF para web e revisar CORS/Sanctum; não isentar entrada DEMO. Logs sem documentos, tokens ou corpos integrais, com rotação e retenção técnica proposta de 7–14 dias. Não exibir debug ou detalhes de infraestrutura.

## 8. Dependências do VetorPest e separação futura

Namespace `App/Models/PestControl`, controllers web/API, serviços de permissão/provisionamento/geolocalização/visitas/auditoria e requests próprios. Tabelas exclusivas: `pest_control_lookups`, `pest_control_species`, `pest_control_products`, `pest_control_establishments`, `pest_control_control_points`, `user_pest_control_permissions`, `pest_control_audit_logs`, `pest_control_visits`, `pest_control_visit_inspections`, `pest_control_inspection_species`, `pest_control_visit_media`, `pest_control_visit_signatures` e `pest_control_technicians` (migrations correspondentes).

Compartilha `User`, `Tenant`, autenticação/Sanctum, trait/escopo, armazenamento, módulo/assinatura/Pix e frontend. `AppServiceProvider` registra provisionador; `User` contém vínculo e identificação de técnico; API e middleware Inertia expõem metadados de módulos/permissões; sidebar e assinatura usam configuração do adicional. Owner tem permissão Pest implícita, ainda dependendo de módulo ativo. Não basta apagar concessões.

Web em `/app/pest-control`, API em `/api/pest-control/v1`; há menu condicionado ao módulo. `vp-app` é aplicativo técnico Expo com agenda offline/SQLite, sincronização, check-in geográfico, fotos e assinatura. `AuxiliaryAppController` oferece APK técnico condicionado ao módulo, mas URLs estáticas em `/apk` precisam de bloqueio independente do menu.

Plano DEMO: não registrar rotas Pest, não provisionar módulo e negar ativação/assinatura do adicional; remover menu, ofertas, metadados e links técnicos; não distribuir APKs técnicos nem incluir seeds, mídias e permissões Pest. Bloquear acessos diretos web/API/static com 404. Evitar publicar nomes de rotas via Ziggy ou chunks Pest no artefato DEMO quando exigida ocultação completa. Não excluir componentes do repositório nesta etapa.

Separação futura: extrair Pest para pacote/contexto de domínio com providers, rotas, migrations, permissões e assets próprios; manter interfaces de tenancy, identidade, assinatura e arquivos no núcleo. Depois avaliar produto/instância separados, com mapa de FKs e migração por tenant, preservação de UUIDs, evidências e auditoria. Nenhuma exclusão de tabela enquanto consumidores móveis e relações não forem migrados e homologados.

## 9. Restauração e salvaguardas

Recomendar **gerador determinístico + snapshot sintético versionado + pool de bases descartáveis**. Seeder cria a origem sob controle; snapshot reduz latência de entrada. Laravel suporta seeders e ordem de geração, mas desativa mass assignment durante seeding: a proteção deve existir no gerador e na infraestrutura, conforme [documentação de seeding](https://laravel.com/framework/docs/12.x/seeding). Não usar `migrate:fresh` genérico como rotina pública.

Ao expirar: marcar reserva indisponível → revogar sessões/tokens → interromper/descartar jobs daquela reserva → aguardar requisições ativas → recriar somente recurso DEMO previamente registrado → restaurar snapshot e arquivos sintéticos → limpar cache por namespace → validar contagens/checksum → liberar reserva. Sem restauração global enquanto visitantes operam. Agendamento periódico mantém o pool e remove sessões/uploads expirados; periodicidade inicial de cinco minutos para expiração e renovação diária da origem, a homologar. Reiniciar container não restaura sozinho um volume persistente.

Salvaguardas cumulativas: rede incapaz de alcançar produção; nenhum segredo ou volume produtivo; conta de banco limitada a bases registradas DEMO; app sem privilégios DDL e restaurador separado; identidade imutável do ambiente verificada por manifesto externo confiável e sentinela no destino; allowlist de host/base/volume, caminho canônico sem symlinks para fora; recusa em caso de divergência, ausência de marcador ou `APP_ENV` inesperado. Prefixo `demo_` e flag de ambiente sozinhos não bastam. Não aceitar destino da requisição nem credenciais de produção como fallback.

Restaurador deve funcionar sem Docker socket privilegiado: acesso a esse socket pode invalidar a segregação. Locks impedem dois resets da mesma reserva. Falhas deixam base em quarentena, sem disponibilizar conteúdo parcial; métricas, prazo máximo e alarme local. Logs ficam fora da área descartável. Workers são processos duradouros e precisam de release/configuração próprios, conforme [documentação de filas](https://laravel.com/framework/docs/12.x/queues).

## 10. Segurança, desempenho e homologação necessária

Capacidade **não medida**. Hipótese inicial: 10 sessões editáveis simultâneas, testes a 1/5/10/20 visitantes; excedente recebe espera ou leitura. Se cada sessão clonar a massa inteira, 10 bases contêm cerca de 1.500 clientes, 3.000 produtos e 2.000 pedidos, além de itens; medir tamanho físico, arquivos, conexões e custo de restore. Proposta de laboratório: VM 2 vCPU/4 GB RAM e 20–40 GB de disco, sem garantia de capacidade. Impor limites de CPU/RAM/PIDs/conexões e evitar que testes de carga usem produção.

Testes futuros obrigatórios: IDOR entre tenants/reservas em web/API/relações/exportações/arquivos; contexto ausente; adulteração de tenant/role/conexão; cadastro/reset/login alternativo; autorização de seller/owner/demo; CSRF/fixação/expiração; token revogado; acesso Pest direto e APK; chamadas externas pelo serviço, jobs e navegador; manipulação de preços/descontos/Flex; estoque concorrente, edição/cancelamento; reserva/expiração/reset simultâneos; falhas no meio do restore; uploads e traversal; limites de datas/volume; rate limits e carga sustentada.

Testar proteção destrutiva em laboratório com destinos-canário representando produção: cada erro de host/base/credencial/volume/manifesto deve falhar antes de qualquer escrita. Comprovar bloqueio de rede e ausência de credenciais produtivas. Usar testes existentes (`TenantIsolationTest`, `ProductStockAdjustmentTest`, `ProductRegionPriceTest`, testes de catálogo, pedidos, relatórios e módulos) somente em banco descartável explicitamente validado; esta auditoria não afirma que passaram.

Aceitação proposta: zero vazamentos/interferências, zero saída externa operacional, matemática validada, reset idempotente e isolado; sob 10 visitantes, p95 de navegação até 2 s e entrada com base pronta até 5 s, erros abaixo de 1%, sem OOM e sem degradação produtiva. Metas são critérios de homologação, não resultados obtidos.

## 11. Custos, esforço e plano faseado

Estimativa de engenharia, sem cotação nem compromisso: **21–35 dias úteis de uma pessoa**, mais contingência de 20–30% e tempo de revisão/homologação. Infra depende do provedor e consumo: solicitar cotação de VM/banco/disco/backup/tráfego e TLS/DNS; não apresentar preço de serviço não verificado. Modelo de custo mensal = app + banco/pool + armazenamento/logs + tráfego + observabilidade; manutenção estimada de 2–4 h/semana no início. Host produtivo compartilhado reduz custo direto, mas exige capacidade disponível e mantém risco de recursos/kernel. Pools limitados evitam custo crescente por visitante.

Todos os arquivos novos abaixo são **propostos**, não criados. Infra será definida em diretório DEMO próprio dentro do projeto/checkout autorizado, após aprovação.

| Fase | Objetivo e alterações / arquivos previstos | Dependências e riscos | Testes e aceitação | Rollback | Esforço |
|---|---|---|---|---|---|
| DEMO-01 | Isolar VM/rede/app/banco/volumes; `infra/demo/compose.yml`, configuração de proxy, `Dockerfile`, `.dockerignore`, exemplo de ambiente e `config/demo.php` | Escolha de host/DNS; risco de montagem, conexão ou imagem contaminada | Conexões produtivas inacessíveis, volumes/segredos próprios, quotas e imagem auditada | Retirar endpoint DEMO e parar apenas recursos identificados DEMO | 2–4 dias |
| DEMO-02 | Gerador e validação da massa; `database/seeders/DemoSeeder.php`, factories comerciais, `app/Services/Demo/*`, manifesto/snapshot | DEMO-01 e regras/schema confirmados; risco de matemática/FKs e contexto ausente | Determinismo, contagens, FK, saldo/Flex e KPIs reconciliados | Descartar origem inválida e manter último snapshot compatível | 3–5 dias |
| DEMO-03 | Reserva independente, conexão imposta, usuário restrito e expiração; `routes/demo.php`, `bootstrap/app.php`, controllers/middleware/serviços DEMO, `config/session.php`, migration de reservas | DEMO-01/02; corrida de reserva, conexão residual em processos persistentes | Dois visitantes não compartilham dados; expiração/token/cookie e conexão testados | Desabilitar entrada e revogar somente reservas DEMO | 4–6 dias |
| DEMO-04 | Allowlist de ações, negação administrativa/Pest/cobrança e adapters locais; `routes/{app,api,admin}.php`, middleware DEMO, serviços de pagamento/mail, `config/services.php`, provider, Inertia/sidebar/assinatura/APKs | DEMO-03; owner implícito, rotas alternativas, saída pelo navegador | 403/404 por acesso direto, zero chamadas reais, payload sem permissão/módulo Pest | Manter DEMO fechada e reverter imagem; nunca reabrir versão sem bloqueios | 3–5 dias |
| DEMO-05 | Restaurador, TTL, locks, limpeza por namespace e retenção; comandos/jobs/services DEMO, `routes/console.php`, volumes/config/logging | DEMO-01/02/03/04; reset de base ativa ou destino errado | Canários, restore concorrente/idempotente, quarentena, nenhuma escrita fora de recurso DEMO | Desativar restauração/entrada, preservar logs e último snapshot íntegro | 3–5 dias |
| DEMO-06 | Botão, boas-vindas, roteiro, avisos e PDFs marcados; `resources/js/pages/site/components/{hero-section,header,cta-section}.tsx`, tela DEMO, templates PDF | DEMO-03/04/05; link/cookie incorreto, publicidade de função ausente | Fluxo sem cadastro, acessível/mobile, marca DEMO, produção sem sessão DEMO | Remover/desativar botão e voltar assets anteriores | 1–2 dias |
| DEMO-07 | Segurança, concorrência e homologação; `tests/Feature/Demo/*`, suites atuais, cenário de carga isolado, checklist e evidências | Fases 01–06; efeitos destrutivos se teste apontar destino incorreto | Todos os critérios da seção 10 e bloqueios negativos aprovados | Bloquear promoção e corrigir ambiente DEMO | 4–6 dias |
| DEMO-08 | Publicação controlada, observação e runbook; configuração de gateway/DNS DEMO, pipeline independente, runbook | DEMO-07 aprovado e autorização expressa para implantação; risco de rota/versão/incidente | Primeiro acesso limitado, métricas/reset/saída verificados e aprovação comercial | Desativar botão/ingresso DEMO, revogar sessões, voltar release e snapshot compatíveis | 1–2 dias |

Fases são entregas revisáveis e reversíveis; suas dependências impedem publicar etapa incompleta. Alteração de schema requer estratégia de snapshot compatível, não restauração cega sobre versão diferente. Nenhum rollback pode usar banco, volumes ou migrations de produção.

## 12. Pendências e decisões necessárias

1. Aprovar C com VM separada e pool de bancos por sessão, ou optar por leitura enquanto o isolamento editável não estiver homologado.
2. Confirmar topologia autorizada, configuração efetiva, engine/versão do banco, gateway/limites, workers, scheduler, armazenamento e serviços compartilhados; sem leitura de dados pessoais.
3. Confirmar release produtivo versus alterações locais e rota da landing realmente publicada; validar o cadastro duplicado de rotas em laboratório.
4. Definir orçamento/cotação, limite de visitantes, TTL, retenção e responsável pela operação; ajustar dimensionamento após medir carga.
5. Aprovar mapa de permissões comerciais DEMO e tratamento visual de documentos e funções de cobrança/fiscal inexistentes.
6. Aceitar explicitamente que fornecedores, histórico de movimentações e financeiro completo não fazem parte do produto identificado; não desenvolver tais módulos para compor a massa.
7. Definir plano/ciclo/limites sintéticos que aceitem 8 representantes e o volume proposto sem liberar cobrança real.
8. Aprovar roteiro comercial e homologação antes de disponibilizar botão público.
9. Autorizar expressamente a implementação e, posteriormente, a implantação controlada. **A execução desta solicitação encerra-se no relatório.**

---

# Histórico preservado — relatório anterior

O conteúdo abaixo antecede VP-DEMO-001 e não representa ações executadas nesta auditoria.

# Preço Regional com Exceção por Produto — Execução

## 1. Descoberta

### Como o preço funciona hoje

O "ajuste percentual por região" **não é um campo na região**. A região (`regions`, model `Region`) só tem `name`, `description`, `status`. O ajuste percentual vem de `commercial_conditions` (model `CommercialCondition`), que é um mecanismo mais genérico de condição comercial com `scope_type` em `global | customer | region | establishment_type | campaign`, cada um carregando seu próprio `price_adjustment_percentage`, `max_discount_percentage`, `minimum_order_amount`, `minimum_order_quantity`, `payment_terms`, `commission_percentage`.

Resolução por cliente: `CommercialCondition::resolveForCustomer(Customer $customer)` busca todas as condições `active()` cujo escopo bate com o cliente (global sempre bate; `customer` bate pelo `customer_id`; `region` bate por `customer->region_id`; `establishment_type` bate pelo tipo) e escolhe a de maior prioridade: **cliente > região > tipo de estabelecimento > global**. Campanhas (`scope_type = 'campaign'`) têm uma condição própria vinculada à campanha e, quando o produto do item pertence à campanha ativa, essa condição substitui a do cliente **apenas para aquele item** — prioridade máxima, já existente.

`CommercialCondition::adjustedPrice(float $price)` aplica o percentual: `round($price * (1 + pct/100), 2)`.

### Onde o cálculo é feito hoje (duplicado em 3 lugares)

1. `app/Http/Controllers/OrderController.php@store` — cria pedido pela tela (Inertia/web).
2. `app/Http/Controllers/Api/ApiOrderController.php@store` — cria pedido pela API (app de vendas / `sales-app`).
3. `app/Services/OrderUpdateService.php@update` — usado tanto por `OrderController@update` quanto por `ApiOrderController@update` para editar pedido.

Nos três, a lógica é a mesma: escolher a condição do item (`campaign->commercialCondition` se o produto pertence à campanha ativa, senão a condição resolvida do cliente) e aplicar `adjustedPrice()` sobre `product->price`, ou usar `product->price` puro se não houver condição. **É exatamente essa duplicação que o serviço central de precificação elimina.**

O preço resolvido é sempre copiado para `order_items.price` no momento da criação (snapshot) — `OrderItem` não recalcula preço dinamicamente; pedidos existentes não seriam afetados por mudança de configuração posterior. Isso já era o comportamento e foi preservado.

### Telas administrativas relacionadas

- Produto: `resources/js/pages/app/products/{index,create-product,edit-product}.tsx` + `ProductController`.
- Região: `resources/js/pages/app/regions/{index,create-region,edit-region}.tsx` + `RegionController`.
- Condição comercial (onde hoje se cadastra o "ajuste de região"): `resources/js/pages/app/commercial-conditions/*` + `CommercialConditionController`.

### Multitenant

Todo o domínio (`Product`, `Region`, `Customer`, `CommercialCondition`, `Order`) usa a trait `Tenantable`, que aplica um `TenantScope` global (filtra automaticamente por `tenant_id` em toda query) e preenche `tenant_id` na criação a partir do usuário autenticado. Não existe auditoria genérica no app principal (só o módulo `PestControl` tem `AuditLog`, isolado e sem relação com produtos/preços/pedidos).

### Decisão: nova tabela, não reaproveitar `commercial_conditions`

`commercial_conditions` resolve **uma condição por cliente**, aplicada uniformemente a todos os produtos daquele cliente. Ela não tem `product_id` e não pode expressar "este produto específico tem um preço fixo nesta região". Forçar isso nela exigiria uma condição por combinação produto×região com `scope_type` novo e reescrever toda a prioridade cliente>região>estabelecimento>global — universo de mudança bem maior que o necessário e que colidiria com o uso já existente da tabela (negociações por cliente, campanhas). Por isso foi criada a tabela nova `product_region_prices`, dedicada exclusivamente à exceção produto×região, reaproveitando o padrão de `Tenantable`, `decimal(10,2)` para dinheiro e nomenclatura já usados no restante do projeto.

## 2. Decisão arquitetural sobre prioridade (confirmada com o usuário)

O spec descreve só 3 níveis (especial produto×região > % de região > base), mas o sistema real tem também condição de cliente, tipo de estabelecimento, global e campanha. Confirmado com o usuário: **o preço especial produto×região tem prioridade sobre qualquer condição comercial resolvida** (cliente, região, tipo de estabelecimento, global), pois "vale só para a região a que pertence, mas nela vence tudo". A única exceção mantida foi **campanha ativa**, que já tinha prioridade máxima antes desta mudança (é o equivalente ao "Promoção" do roadmap futuro da seção 15 do escopo, que o próprio spec já prevê ficar acima do preço especial produto×região) — não desativar promoções ativas é o comportamento menos arriscado e o mais alinhado ao próprio roadmap do spec. **Ponto para revisão**: se o usuário quiser que o preço especial também vença uma campanha ativa para aquele produto, é uma mudança pequena e localizada (bastaria não zerar `$itemRegion` quando o item pertence à campanha nos 3 pontos de integração).

## 3. Migrations

`database/migrations/2026_09_05_000001_create_product_region_prices_table.php` — cria `product_region_prices`:

```
id
tenant_id   FK nullable -> tenants, cascadeOnDelete   (padrão Tenantable)
product_id  FK -> products, cascadeOnDelete
region_id   FK -> regions, cascadeOnDelete
special_price  decimal(10,2)
is_active   boolean default true
valid_from  timestamp nullable
valid_until timestamp nullable
timestamps

unique(tenant_id, product_id, region_id)
```

`special_price` é `decimal(10,2)` (nunca float), igual ao padrão de `products.price` e `commercial_conditions.*_percentage`. A combinação é única por tenant: existe no máximo um registro de exceção por produto+região — ativar/desativar/reprogramar validade é feito por `update` no mesmo registro (não há histórico multi-linha, ver "pontos de revisão" abaixo).

Migration executada em desenvolvimento (`php artisan migrate --force`), sem erros.

## 4. Backend

### Model `App\Models\ProductRegionPrice`

`Tenantable`; casts (`special_price` decimal:2, `is_active` boolean, `valid_from`/`valid_until` datetime); `scopeActive()` (is_active + janela de validade); `isCurrentlyValid()`. Relações `Product::regionPrices()` e `Region::productPrices()` adicionadas.

### Serviço central — `App\Services\Pricing\RegionalPriceResolver`

Único ponto de cálculo de preço regional do sistema (nenhum outro lugar recalcula):

- `resolve(Product $product, Region $region): array` — visão administrativa (produto×região "pura", sem cliente): retorna `basePrice`, `regionPercentage`, `calculatedRegionalPrice`, `specialPrice`, `effectivePrice`, `source` (`base | regional_percentage | special_region_price`). Usado nas telas de produto e de região.
- `effectivePriceForSale(Product $product, ?Region $region, ?CommercialCondition $condition): float` — usado na venda/pedido: se existir preço especial ativo para produto+região, ele vence (nenhum percentual aplicado sobre ele); senão aplica a condição já resolvida pelo fluxo existente (cliente/região/estabelecimento/global), ou o preço base se não houver condição.
- `activeSpecialPrice(int $productId, int $regionId): ?ProductRegionPrice` — helper único de consulta reaproveitado pelos dois métodos acima.

### Integração nos 3 pontos que calculavam preço (agora delegam ao serviço)

- `OrderController@store`
- `Api\ApiOrderController@store`
- `OrderUpdateService@update` (injeção via construtor, resolvido pelo `app(OrderUpdateService::class)` já usado nos controllers)

Em todos, a região usada é a do cliente (`$customer->region`); para itens de campanha ativa, a região é `null` (não busca preço especial), preservando a prioridade de campanha citada acima.

### CRUD da exceção

- `App\Http\Requests\ProductRegionPriceRequest` — valida `region_id` (existe e pertence ao tenant do usuário + único por produto/tenant, ignorando o registro atual em updates), `special_price` (`numeric`, `min:0` — impede negativo), `is_active`, `valid_from`/`valid_until` (`after_or_equal:valid_from`).
- `App\Http\Controllers\ProductRegionPriceController` — `store` / `update` / `destroy`, autorização igual à de produtos (`canManageTeam()`), com checagem extra de que o `regionPrice` pertence ao `product` da rota.
- Rotas em `routes/app.php` (mesmo grupo/prefixo `app.` dos demais recursos):
  - `POST   /products/{product}/region-prices`
  - `PATCH  /products/{product}/region-prices/{regionPrice}`
  - `DELETE /products/{product}/region-prices/{regionPrice}`

Isolamento de tenant garantido em duas camadas: o `{product}` do binding de rota já é filtrado pelo `TenantScope` (produto de outro tenant → 404) e o `region_id` do payload é validado contra `tenant_id` do usuário autenticado (região de outro tenant → 422 com erro de validação).

### `ProductController::show` e `RegionController::edit`

- `ProductController::show` monta, para cada região ativa, o resultado de `RegionalPriceResolver::resolve()` + os dados do registro de exceção (se existir), e passa como prop `regionPrices` para a tela do produto.
- `RegionController::edit` passa a receber busca (`q`) e pagina (15) os produtos, cada um já resolvido para aquela região via `RegionalPriceResolver::resolve()`, como prop `productPrices` (visão somente leitura, conforme pedido no escopo).

## 5. Frontend

- `resources/js/pages/app/products/edit-product.tsx`: nova seção "Preços por região" (tabela Região | Ajuste | Calculado | Preço especial | Preço efetivo | Ações). Cada linha permite adicionar/editar (preço especial, ativo, validade opcional) ou remover a exceção daquela região, com confirmação de exclusão. Quando o preço especial está em uso, aparece um badge "Preço especial" ao lado do preço efetivo, deixando visualmente claro quando ele está substituindo a regra regional.
- `resources/js/pages/app/regions/edit-region.tsx`: nova seção "Produtos desta região" — tabela paginada e pesquisável (Produto | Base | Calculado | Especial | Final), somente leitura (edição continua pelo cadastro do produto, conforme pedido para não ampliar escopo com edição em massa).

Ambas seguem os componentes/convenções já usados no projeto (`Table`, `Badge`, `AlertDialog`, `Switch`, `AppPagination`, `maskMoney`, `route()`), sem introduzir bibliotecas novas.

## 6. Regra de resolução de preço (resumo final)

```
1. Campanha ativa para o produto           -> mantém comportamento já existente, sem alterações
2. Preço especial Produto×Região ativo     -> vence cliente, região, tipo de estabelecimento e global
3. Condição comercial resolvida do cliente -> percentual aplicado sobre o preço base
4. Nenhuma condição                        -> preço base
```

Validade do preço especial: `is_active = true` E (`valid_from` nulo OU `<= agora`) E (`valid_until` nulo OU `>= agora`); fora disso, cai automaticamente para o passo 3.

## 7. Testes executados

Novo arquivo `tests/Feature/ProductRegionPriceTest.php`, cobrindo os 10 casos pedidos no escopo + 1 teste de ponta a ponta do critério de aceite (seção 17) com snapshot de pedido (seção 11):

1. Produto sem região → preço base.
2. Região +5% sem especial → 100 → 105.
3. Região +5% com especial 102,90 → usa 102,90 (inclusive vencendo uma condição comercial de outro escopo, cenário confirmado com o usuário).
4. Mesmo produto em duas regiões → cada uma resolve independentemente.
5. Especial desativado → volta ao percentual.
6. Especial expirado (`valid_until` passado) e ainda não iniciado (`valid_from` futuro) → volta ao percentual.
7. Região sem percentual cadastrado → preço base.
8. Arredondamento monetário (79,99 × 1,05 = 83,9895 → 83,99).
9. Preço especial negativo → rejeitado na validação (`special_price`).
10. Vínculo com região de outro tenant → rejeitado na validação; produto de outro tenant → 404 (route binding).
11. (Extra, ponta a ponta) Criação de pedido pela rota real: produto com especial na região do cliente usa o especial; o mesmo produto num pedido de cliente de outra região usa o percentual normal; e remover o preço especial depois não altera o item do pedido já criado.

Resultado: `php artisan test` — **200 passed (1429 assertions)**, suite completa (incluindo os 11 novos testes), sem nenhuma regressão nos testes já existentes (`CommercialConditionUniquenessTest`, `TenantIsolationTest`, `ProductStockAdjustmentTest`, etc.).

`vendor/bin/pint --dirty --test` → passou. `npx tsc --noEmit` → sem erros. `npm run build` → build concluído com sucesso (assets de build gerados foram restaurados ao estado original do git depois da verificação, para não sujar o diff com hashes de arquivos que não mudaram de conteúdo fonte).

`npx eslint` nos dois arquivos `.tsx` alterados aponta erros de `no-explicit-any`, mas isso já é pré-existente no projeto: rodando o mesmo lint no `git stash` (código antes desta mudança) os mesmos 2 arquivos já tinham 13 erros de `any`; o projeto inteiro (`npx eslint .`) já falha hoje com 601 problemas na `main`, sem relação com esta feature. As poucas ocorrências novas de `any` introduzidas seguem exatamente o mesmo padrão já usado nesses componentes (ex.: `export default function CreateProduct({ product }: any)`), não uma regressão de qualidade nova.

## 8. Resultado final

Funcionalidade implementada conforme o critério de aceite da seção 17: um produto com preço base R$100 numa região com +5% mostra R$105 por padrão; ao cadastrar um preço especial de R$103,50 para aquele produto/região, o sistema passa a usar R$103,50 **somente** para aquela combinação — outros produtos da mesma região continuam em +5%, e o mesmo produto em outra região continua obedecendo à regra dessa região. Comportamento validado tanto no serviço isoladamente quanto no fluxo real de criação de pedido.

Nenhum preço existente foi alterado automaticamente; nenhum pedido/venda já registrado é afetado (snapshot preservado); nenhuma exceção é criada para registros antigos (tabela nova, vazia até o operador cadastrar).

## 9. Arquivos alterados

**Novos:**
- `database/migrations/2026_09_05_000001_create_product_region_prices_table.php`
- `app/Models/ProductRegionPrice.php`
- `app/Services/Pricing/RegionalPriceResolver.php`
- `app/Http/Requests/ProductRegionPriceRequest.php`
- `app/Http/Controllers/ProductRegionPriceController.php`
- `tests/Feature/ProductRegionPriceTest.php`
- `executed.md` (este arquivo)

**Modificados:**
- `app/Models/Product.php` (relação `regionPrices()`)
- `app/Models/Region.php` (relação `productPrices()`)
- `app/Http/Controllers/OrderController.php` (usa o resolver no `store`)
- `app/Http/Controllers/Api/ApiOrderController.php` (usa o resolver no `store`)
- `app/Services/OrderUpdateService.php` (usa o resolver no `update`)
- `app/Http/Controllers/ProductController.php` (`show` expõe `regionPrices`)
- `app/Http/Controllers/RegionController.php` (`edit` expõe `productPrices` paginado/pesquisável)
- `routes/app.php` (rotas de `products.region-prices.*`)
- `resources/js/pages/app/products/edit-product.tsx` (seção "Preços por região")
- `resources/js/pages/app/regions/edit-region.tsx` (seção "Produtos desta região")

## 10.1 Ajuste pós-entrega (UX do formulário de cadastro)

Após a primeira entrega, foi reportado que a tela do produto não deixava claro onde ficavam os campos de região e preço especial. Diagnóstico: o backend estava correto (confirmado consultando o payload Inertia real de `app.products.show` com dados de teste — o `regionPrices` chegava com `region_id`, `specialPrice`, `effectivePrice` e `source` certos), mas a interface só mostrava uma tabela com uma linha por região, e o campo de valor só aparecia ao clicar no lápis daquela linha — sem nenhum `<select>` de região visível, que era o pedido original.

Correção em `resources/js/pages/app/products/edit-product.tsx`: adicionado um formulário fixo "Adicionar preço especial", acima da tabela, com um `<select>` de região (listando só as regiões que ainda não têm exceção cadastrada) e o campo "Preço especial (R$)" (mais validade opcional). A tabela abaixo continua mostrando o resumo de todas as regiões e permite editar/remover uma exceção já existente pelo ícone de lápis/lixeira. Validado via `tsc --noEmit`, recompilação pelo Vite (módulo servido sem erro pelo dev server) e reexecução dos testes/Pint — tudo passando.

## 10.2 Segundo ajuste pós-entrega (checkbox + prioridade sobre campanha)

Pedido do usuário: (1) a interface deveria ser um **checkbox "Aplicar preço especial"** que revela o select de região + o campo de valor (em vez do formulário sempre visível), presente **tanto na tela de inserção quanto na de edição** do produto; e (2) confirmado explicitamente: o preço especial deve substituir **todas** as regras, inclusive uma **campanha promocional ativa** — não só condição comercial de cliente/região/estabelecimento/global como decidido antes.

**Mudança de prioridade** (revoga a decisão registrada em 10 — ponto 1 abaixo foi resolvido): nos três pontos de cálculo (`OrderController@store`, `Api\ApiOrderController@store`, `OrderUpdateService@update`), a região do cliente agora é sempre passada ao resolver, mesmo para itens de campanha — antes, um item de campanha não considerava preço especial (região `null`), preservando a prioridade da campanha; agora `RegionalPriceResolver::effectivePriceForSale()` verifica o preço especial primeiro em qualquer caso, e só cai para a condição resolvida (que pode ser a de campanha) quando não há especial ativo. Documentação do serviço atualizada para refletir a nova prioridade: **especial > (campanha | cliente | região | tipo de estabelecimento | global) > base**.

**Interface**: em `edit-product.tsx`, o formulário "Adicionar preço especial" agora fica atrás de um `Checkbox` "Aplicar preço especial" (só aparece o select de região + input de valor quando marcado). Em `create-product.tsx` (tela de inserção), foi adicionado o mesmo padrão — checkbox, select de região (nova prop `regions`, agora enviada por `ProductController::create()`) e campo de valor — permitindo já cadastrar um preço especial no momento da criação do produto. `ProductRequest` ganhou os campos opcionais `apply_special_price`, `special_price_region_id`, `special_price_value`, `special_price_valid_from/until` (obrigatórios apenas quando o checkbox é marcado); `ProductController::store()` cria o `ProductRegionPrice` correspondente logo após salvar o produto.

Testes adicionados/ajustados em `tests/Feature/ProductRegionPriceTest.php`: preço especial vencendo uma campanha ativa (unitário no resolver + pedido real fim a fim), preço especial aplicado já na criação do produto, e rejeição de checkbox marcado sem preencher região/valor. Suite completa após o ajuste: **203 passed (1444 assertions)**, Pint e `tsc --noEmit` limpos. Validado também via requisições reais contra o servidor de desenvolvimento (login, `GET /app/products/create` retornando a prop `regions`, `POST /app/products` com o checkbox aplicado gerando o `ProductRegionPrice` esperado) usando um tenant temporário criado e removido só para o teste, sem tocar em dados reais do usuário.

## 10.3 Terceiro ajuste pós-entrega (máscara de moeda)

Pedido: aplicar máscara de moeda ao campo de preço especial. Reaproveitado o mesmo padrão já usado no campo "Preço" do produto (`maskMoney` para exibição com vírgula/milhar, `maskMoneyDot` normalizando o valor digitado para o formato decimal com ponto que a API espera), aplicado nos três campos de preço especial: formulário "Adicionar preço especial" e formulário de edição de linha em `edit-product.tsx`, e o campo da tela de criação em `create-product.tsx`. Ajuste adicional necessário: ao pré-carregar o valor de uma exceção já existente para edição, o valor agora é normalizado com `.toFixed(2)` antes de entrar na máscara — sem isso, um valor como `102.9` (sem o zero final, como o PHP/JSON representa o float) seria corrompido pela máscara (viraria `10,29`). Validado com `tsc --noEmit` (precisou de um `?? ''` para compatibilizar o retorno `string | undefined` de `maskMoneyDot` com o estado local), Pint e a suíte de testes — sem regressão.

## 10.4 Quarto ajuste pós-entrega (preço especial na listagem)

Pedido: na tabela inicial de produtos (`app.products.index`), quando houver preço especial ativo, ele deve ser mostrado. Como essa listagem não tem contexto de cliente/região (é a visão geral do catálogo, não uma venda), o preço base é mantido como referência, mas agora exibido riscado quando existe ao menos um preço especial ativo, com o(s) preço(s) especial(is) destacado(s) logo abaixo, um por região (um produto pode ter mais de um preço especial ativo, um por região).

Backend (`ProductController::index`): eager load de `regionPrices` já filtrado pelo escopo `active()` (respeitando `is_active` e a janela de validade) com a região carregada; a listagem passa a expor `special_prices: [{region_id, region_name, special_price}]` por produto (vazio quando não há exceção ativa).

Frontend (`resources/js/pages/app/products/index.tsx`): a coluna "Preço" mostra só o preço base quando não há especial; quando há, mostra o preço base riscado e, abaixo, cada preço especial ativo com um badge indicando a região.

Teste adicionado (`the products listing shows active special prices per region`) cobrindo: produto com dois preços especiais ativos (em regiões diferentes), produto com preço especial desativado (não deve aparecer) e produto sem nenhuma exceção. Suite completa após o ajuste: **204 passed (1449 assertions)**. Validado também com requisição real (login + `GET /app/products`) contra um tenant temporário removido logo em seguida.

## 10.5 Quinto ajuste pós-entrega (checkbox "Tempo indeterminado")

Pedido: adicionar um checkbox "tempo indeterminado" para o caso (raro, mas já suportado) de o preço especial não ter data de expiração. Isso já era possível deixando "Válido de"/"Válido até" em branco (a regra de negócio — seção 12 do escopo — já trata `valid_from`/`valid_until` nulos como "sem limite"), mas agora fica explícito na interface.

Adicionado nos três formulários que lidam com validade (formulário "Adicionar preço especial" e formulário de editar uma exceção existente, em `edit-product.tsx`; e o formulário da tela de criação do produto, em `create-product.tsx`): um checkbox **"Tempo indeterminado (sem data de expiração)"**, marcado por padrão. Quando marcado, os campos "Válido de"/"Válido até" ficam ocultos e são limpos (enviados como `null`); quando desmarcado, os campos aparecem para definir a janela de validade. No formulário de edição de uma exceção já existente, o checkbox inicia marcado ou desmarcado de acordo com o que já está salvo (se `valid_from`/`valid_until` já estavam vazios, começa marcado). Só interface — nenhuma mudança de backend foi necessária, já que a regra de "sem validade = sempre válido" já existia.

Validado com `tsc --noEmit`, Pint, suíte completa (**204 passed**, sem regressão) e recompilação pelo Vite dos três arquivos alterados.

## 10. Pontos que precisam de revisão

1. ~~**Prioridade sobre campanha**~~ — resolvido em 10.2: confirmado que o preço especial vence também campanha ativa; implementado e testado.
2. **Sem histórico multi-linha**: `product_region_prices` guarda um único registro vigente por produto/região (editado in-place); não existe uma tabela de histórico de alterações de preço especial, porque o app principal não tem um padrão de auditoria genérico hoje (só o módulo de Controle de Pragas tem `AuditLog`, isolado). A seção 16 do escopo ("se o VetorPet já possuir auditoria") portanto não se aplica; se for necessário auditar quem mudou o quê e quando, é um novo componente de auditoria a ser desenhado — não implementado nesta etapa para não expandir escopo.
3. **Lint do frontend**: `npx eslint .` já falhava antes desta mudança (601 problemas na `main`); não foi escopo desta tarefa arrumar o lint do projeto, só evitar piorar o padrão nos arquivos tocados (que já usavam `any` amplamente).
