select 'model_has_roles' t, model_type v, count(*) n from model_has_roles group by v;
select 'model_has_permissions', model_type, count(*) from model_has_permissions group by model_type;
select 'personal_access_tokens', tokenable_type, count(*) from personal_access_tokens group by tokenable_type;
