"""V2 指令系统 — 业务指令已全部移除。

保留模块以兼容旧调用：parse_command() 返回 None；execute() 返回空结果。
新功能可直接在此处重新设计。
"""


def parse_command(text):
    """不再解析任何业务指令，统一返回 None。"""
    return None


def execute(config, store, gid, sender_id, text, at_user_id=None, member_name=None,
            daily_count=None, resolve=None):
    """不再执行业务指令，统一返回无回复。"""
    return {"reply": None}
